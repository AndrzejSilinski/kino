// Klient API — port `frontend/src/api/http.ts` z SPA (decyzja 196).
//
// Reguły przeniesione ze SPA:
//   - sukces zawsze w kopercie `data`; brak koperty to INVALID_RESPONSE,
//   - błąd rozpoznajemy po `code` z ciała, nie po statusie,
//   - brak sieci i timeout dają kod klienta NETWORK_ERROR,
//   - 429 przenosi `Retry-After` do wyjątku,
//   - token bearer dokładamy, gdy sesja go ma; 401 UNAUTHENTICATED oznacza,
//     że serwer go odrzucił, więc czyścimy go u siebie (decyzja 272),
//   - identyfikator sesji zakupowej wysyłamy i zapamiętujemy z KAŻDEJ
//     odpowiedzi, bo to serwer go wydaje (decyzja 15).
//
// Multipart (avatar) dochodzi w bloku J.

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/core/session.dart';
import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient({required this.config, required this.httpClient, this.session});

  /// Tyle czeka telefon w kiepskiej sieci, zanim pokażemy NETWORK_ERROR.
  static const Duration timeout = Duration(seconds: 15);

  final AppConfig config;
  final http.Client httpClient;

  /// Źródło tokenu i sesji zakupowej. Null w testach, które ich nie dotyczą.
  final ApiSession? session;

  /// GET, którego `data` jest OBIEKTEM (np. `/client-config`, `/auth/me`).
  Future<Map<String, Object?>> getJson(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) async => jsonChild(
    await getEnvelope(path, query: query, headers: headers),
    'data',
    path,
  );

  /// GET, którego `data` jest TABLICĄ (listy katalogu). Osobna metoda, bo
  /// koperta listy ma inny kształt niż koperta obiektu i mieszanie ich
  /// kończyłoby się błędem INVALID_RESPONSE dopiero w czasie działania.
  Future<List<Object?>> getList(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) async => jsonList(
    (await getEnvelope(path, query: query, headers: headers))['data'],
    path,
  );

  /// CAŁA koperta: `data` plus `links` i `meta` przy listach paginowanych.
  Future<Map<String, Object?>> getEnvelope(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) => _envelope('GET', path, query: query, headers: headers);

  Future<Map<String, Object?>> postJson(
    String path, {
    Map<String, Object?>? body,
    Map<String, String>? headers,
  }) async =>
      _data(await _envelope('POST', path, body: body, headers: headers), path);

  Future<Map<String, Object?>> putJson(
    String path, {
    Map<String, Object?>? body,
    Map<String, String>? headers,
  }) async =>
      _data(await _envelope('PUT', path, body: body, headers: headers), path);

  Future<Map<String, Object?>> patchJson(
    String path, {
    Map<String, Object?>? body,
    Map<String, String>? headers,
  }) async =>
      _data(await _envelope('PATCH', path, body: body, headers: headers), path);

  /// Odpowiedź BEZ koperty `data`.
  ///
  /// Jedyny taki endpoint w naszym API to `/broadcasting/auth`: zwraca surowe
  /// `{"auth": "..."}`, bo tego wymaga protokół Pushera — tak samo jak dla
  /// `pusher-js` w SPA. Wyjątek jest świadomy i opisany po stronie serwera;
  /// pozostałe metody rozpakowują kopertę, żeby nikt nie sięgał po `data`
  /// ręcznie.
  Future<Map<String, Object?>> postWithoutEnvelope(
    String path, {
    Map<String, Object?>? body,
  }) => _envelope('POST', path, body: body);

  Future<Map<String, Object?>> deleteJson(
    String path, {
    Map<String, String>? headers,
  }) async => _data(await _envelope('DELETE', path, headers: headers), path);

  /// `data` z koperty albo pusta mapa, gdy odpowiedź nie miała ciała (204).
  Map<String, Object?> _data(Map<String, Object?> envelope, String path) =>
      envelope.isEmpty ? envelope : jsonChild(envelope, 'data', path);

  /// Wspólna droga wszystkich metod: nagłówki, wysyłka, koperta, błędy.
  Future<Map<String, Object?>> _envelope(
    String method,
    String path, {
    Map<String, Object?>? body,
    Map<String, String>? query,
    Map<String, String>? headers,
  }) async {
    final Uri uri = config.apiUri(path, query: query);
    final http.Response response = await _send(
      () => _request(method, uri, body, headers),
      path,
    );
    final String? sessionId = response.headers[_sessionHeaderLower];
    if (sessionId != null && sessionId.isNotEmpty) {
      session?.rememberBookingSessionId(sessionId);
    }
    final Object? decoded = _decode(response, path);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      // Odpowiedzi 204 (np. usunięcie urządzenia push) nie mają ciała.
      if (decoded == null) {
        return const <String, Object?>{};
      }
      return jsonMap(decoded, path);
    }
    final ApiError error = ApiError.fromBody(
      response.statusCode,
      decoded,
      retryAfter: parseRetryAfter(response.headers['retry-after']),
    );
    if (error.code == ApiError.unauthenticated) {
      session?.onTokenRejected();
    }
    throw error;
  }

  /// Odpowiedź BINARNA: bajty pliku, nie koperta JSON.
  ///
  /// Nie idzie przez `_envelope`, bo ten dekoduje JSON, a tu treścią jest plik
  /// (PDF z biletami). Wszystko inne zostaje takie samo: te same nagłówki,
  /// czyli i token, i ta sama zamiana błędu na `ApiError` — bo przy statusie
  /// poza 2xx serwer odpowiada zwykłą kopertą błędu, nie plikiem.
  Future<List<int>> getBytes(
    String path, {
    Map<String, String>? headers,
  }) async {
    final Uri uri = config.apiUri(path);
    final http.Response response = await _send(
      () => _request('GET', uri, null, headers),
      path,
    );
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return response.bodyBytes;
    }
    final ApiError error = ApiError.fromBody(
      response.statusCode,
      _decode(response, path),
      retryAfter: parseRetryAfter(response.headers['retry-after']),
    );
    if (error.code == ApiError.unauthenticated) {
      session?.onTokenRejected();
    }
    throw error;
  }

  Future<http.Response> _request(
    String method,
    Uri uri,
    Map<String, Object?>? body,
    Map<String, String>? headers,
  ) {
    final Map<String, String> all = _headers(headers, withBody: body != null);
    final String? encoded = body == null ? null : jsonEncode(body);
    switch (method) {
      case 'POST':
        return httpClient.post(uri, headers: all, body: encoded);
      case 'PUT':
        return httpClient.put(uri, headers: all, body: encoded);
      case 'PATCH':
        return httpClient.patch(uri, headers: all, body: encoded);
      case 'DELETE':
        return httpClient.delete(uri, headers: all, body: encoded);
      default:
        return httpClient.get(uri, headers: all);
    }
  }

  static const String sessionHeader = 'X-Session-Id';
  static const String _sessionHeaderLower = 'x-session-id';

  Map<String, String> _headers(
    Map<String, String>? extra, {
    bool withBody = false,
  }) {
    final String? token = session?.token;
    final String? sessionId = session?.bookingSessionId;
    return <String, String>{
      'Accept': 'application/json',
      if (withBody) 'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
      // Znacznik null-aware stoi przy WARTOŚCI (klucz jest stały): wpis znika,
      // gdy serwer nie wydał jeszcze identyfikatora sesji zakupowej.
      sessionHeader: ?sessionId,
      ...?extra,
    };
  }

  /// Zamienia awarie transportu na jeden kod klienta (NETWORK_ERROR).
  Future<http.Response> _send(
    Future<http.Response> Function() request,
    String path,
  ) async {
    try {
      return await request().timeout(timeout);
    } on TimeoutException {
      throw ApiError.network('timeout $path');
    } on SocketException {
      throw ApiError.network('socket $path');
    } on http.ClientException {
      throw ApiError.network('client $path');
    } on HandshakeException {
      throw ApiError.network('tls $path');
    }
  }

  Object? _decode(http.Response response, String path) {
    if (response.body.isEmpty) {
      return null;
    }
    try {
      return jsonDecode(response.body);
    } on FormatException {
      throw ApiError.malformedResponse(path);
    }
  }

  /// `Retry-After` w sekundach (tak wysyła je Laravel).
  static Duration? parseRetryAfter(String? value) {
    if (value == null) {
      return null;
    }
    final int? seconds = int.tryParse(value.trim());
    if (seconds == null || seconds < 0) {
      return null;
    }
    return Duration(seconds: seconds);
  }
}
