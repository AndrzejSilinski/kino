// Port zachowań `frontend/src/api/http.ts` na Darta. Te same przypadki, które
// w SPA sprawdza Vitest: koperta data, kody błędów z ciała, 422 z errors,
// 429 z Retry-After, brak sieci jako kod klienta.

import 'dart:convert';
import 'dart:io';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

ApiClient clientReturning(
  http.Response Function(http.Request request) handler,
) {
  return ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async => handler(request)),
  );
}

http.Response jsonResponse(
  int status,
  Object body, {
  Map<String, String> headers = const <String, String>{},
}) {
  return http.Response(
    jsonEncode(body),
    status,
    headers: <String, String>{'content-type': 'application/json', ...headers},
  );
}

void main() {
  test('zwraca zawartość koperty data i woła właściwy adres', () async {
    Uri? called;
    final ApiClient client = clientReturning((http.Request request) {
      called = request.url;
      return jsonResponse(200, <String, Object?>{
        'data': <String, Object?>{'api_version': 'v1'},
      });
    });

    final Map<String, Object?> data = await client.getJson('/client-config');

    expect(data['api_version'], 'v1');
    expect(called.toString(), 'http://localhost:8080/api/v1/client-config');
  });

  test('odpowiedź bez koperty data to INVALID_RESPONSE', () async {
    final ApiClient client = clientReturning(
      (http.Request request) =>
          jsonResponse(200, <String, Object?>{'api_version': 'v1'}),
    );

    await expectLater(
      client.getJson('/client-config'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.invalidResponse,
        ),
      ),
    );
  });

  test('treść, która nie jest JSON-em, to INVALID_RESPONSE', () async {
    final ApiClient client = clientReturning(
      (http.Request request) => http.Response('<html>502</html>', 200),
    );

    await expectLater(
      client.getJson('/client-config'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.invalidResponse,
        ),
      ),
    );
  });

  test('błąd serwera przenosi code i message z ciała', () async {
    final ApiClient client = clientReturning(
      (http.Request request) => jsonResponse(404, <String, Object?>{
        'message': 'Nie znaleziono zasobu.',
        'code': 'RESOURCE_NOT_FOUND',
      }),
    );

    try {
      await client.getJson('/screenings/999999999');
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.resourceNotFound);
      expect(error.message, 'Nie znaleziono zasobu.');
      expect(error.status, 404);
    }
  });

  test('422 przenosi errors, więc formularz pokaże komunikat pola', () async {
    final ApiClient client = clientReturning(
      (http.Request request) => jsonResponse(422, <String, Object?>{
        'message': 'Podane dane są nieprawidłowe.',
        'code': 'VALIDATION_FAILED',
        'errors': <String, Object?>{
          'email': <String>['Pole adres e-mail jest wymagane.'],
        },
      }),
    );

    try {
      await client.getJson('/auth/login');
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.validationFailed);
      expect(error.fieldError('email'), 'Pole adres e-mail jest wymagane.');
      expect(error.fieldError('password'), isNull);
    }
  });

  test('429 przenosi Retry-After', () async {
    final ApiClient client = clientReturning(
      (http.Request request) => jsonResponse(
        429,
        <String, Object?>{
          'message': 'Zbyt wiele żądań.',
          'code': 'TOO_MANY_REQUESTS',
        },
        headers: <String, String>{'retry-after': '37'},
      ),
    );

    try {
      await client.getJson('/screenings/1/seat-locks');
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.tooManyRequests);
      expect(error.retryAfter, const Duration(seconds: 37));
    }
  });

  test('brak sieci to kod klienta NETWORK_ERROR', () async {
    final ApiClient client = ApiClient(
      config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
      httpClient: MockClient((http.Request request) async {
        throw const SocketException('brak trasy do hosta');
      }),
    );

    await expectLater(
      client.getJson('/client-config'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.networkError,
        ),
      ),
    );
  });

  test('Retry-After przyjmuje sekundy i odrzuca śmieci', () {
    expect(ApiClient.parseRetryAfter('60'), const Duration(seconds: 60));
    expect(ApiClient.parseRetryAfter(' 5 '), const Duration(seconds: 5));
    expect(ApiClient.parseRetryAfter(null), isNull);
    expect(ApiClient.parseRetryAfter('Wed, 21 Oct 2026 07:28:00 GMT'), isNull);
    expect(ApiClient.parseRetryAfter('-1'), isNull);
  });
}
