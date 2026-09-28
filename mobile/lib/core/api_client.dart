// Klient API — port `frontend/src/api/http.ts` z SPA (decyzja 196).
//
// Blok C obsługuje tylko GET bez uwierzytelnienia: tyle wystarczy na
// `/client-config` i na ekran diagnostyczny. Token bearer, sesja zakupowa
// (`X-Session-Id`), POST, PATCH i multipart dochodzą w bloku D.
//
// Reguły przeniesione ze SPA:
//   - sukces zawsze w kopercie `data`; brak koperty to INVALID_RESPONSE,
//   - błąd rozpoznajemy po `code` z ciała, nie po statusie,
//   - brak sieci i timeout dają kod klienta NETWORK_ERROR,
//   - 429 przenosi `Retry-After` do wyjątku.

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/json.dart';
import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient({required this.config, required this.httpClient});

  /// Tyle czeka telefon w kiepskiej sieci, zanim pokażemy NETWORK_ERROR.
  static const Duration timeout = Duration(seconds: 15);

  final AppConfig config;
  final http.Client httpClient;

  /// GET zwracający zawartość koperty `data` jako mapę.
  Future<Map<String, Object?>> getJson(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) async {
    final Uri uri = config.apiUri(path, query: query);
    final http.Response response = await _send(
      () => httpClient.get(uri, headers: _headers(headers)),
      path,
    );
    final Object? body = _decode(response, path);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return jsonChild(jsonMap(body, path), 'data', path);
    }
    throw ApiError.fromBody(
      response.statusCode,
      body,
      retryAfter: parseRetryAfter(response.headers['retry-after']),
    );
  }

  Map<String, String> _headers(Map<String, String>? extra) => <String, String>{
    'Accept': 'application/json',
    ...?extra,
  };

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
