// Token i sesja zakupowa w żądaniach: te reguły przenosimy z http.ts,
// bo od nich zależy, czy blokady miejsc trafią do właściwego koszyka
// i czy wygasły token nie zostanie z nami na 30 dni.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/session.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class FakeSession implements ApiSession {
  FakeSession({this.token, this.bookingSessionId});

  @override
  String? token;

  @override
  String? bookingSessionId;

  bool rejected = false;

  @override
  void rememberBookingSessionId(String value) => bookingSessionId = value;

  @override
  void onTokenRejected() {
    token = null;
    rejected = true;
  }
}

ApiClient clientFor(
  FakeSession session,
  http.Response Function(http.Request request) handler,
) => ApiClient(
  config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
  httpClient: MockClient((http.Request request) async => handler(request)),
  session: session,
);

http.Response ok(Object body, {Map<String, String> headers = const {}}) =>
    http.Response(
      jsonEncode(body),
      200,
      headers: <String, String>{'content-type': 'application/json', ...headers},
    );

void main() {
  test('dokłada token bearer, gdy sesja go ma', () async {
    final FakeSession session = FakeSession(token: '7|abc');
    String? authorization;
    final ApiClient client = clientFor(session, (http.Request request) {
      authorization = request.headers['Authorization'];
      return ok(<String, Object?>{'data': <String, Object?>{}});
    });

    await client.getJson('/auth/me');

    expect(authorization, 'Bearer 7|abc');
  });

  test('bez tokenu nie wysyła nagłówka Authorization', () async {
    final FakeSession session = FakeSession();
    Map<String, String> sent = const <String, String>{};
    final ApiClient client = clientFor(session, (http.Request request) {
      sent = request.headers;
      return ok(<String, Object?>{'data': <String, Object?>{}});
    });

    await client.getJson('/client-config');

    expect(sent.containsKey('Authorization'), isFalse);
  });

  test('wysyła znaną sesję zakupową i zapamiętuje tę z odpowiedzi', () async {
    final FakeSession session = FakeSession(bookingSessionId: 'a' * 32);
    String? sentSession;
    final ApiClient client = clientFor(session, (http.Request request) {
      sentSession = request.headers[ApiClient.sessionHeader];
      return ok(
        <String, Object?>{'data': <String, Object?>{}},
        headers: <String, String>{'x-session-id': 'b' * 32},
      );
    });

    await client.getJson('/screenings/1/seat-map');

    expect(sentSession, 'a' * 32);
    expect(session.bookingSessionId, 'b' * 32);
  });

  test('401 UNAUTHENTICATED czyści token w sesji', () async {
    final FakeSession session = FakeSession(token: '7|abc');
    final ApiClient client = clientFor(
      session,
      (http.Request request) => http.Response(
        jsonEncode(<String, Object?>{
          'message': 'Wymagane jest zalogowanie.',
          'code': 'UNAUTHENTICATED',
        }),
        401,
        headers: <String, String>{'content-type': 'application/json'},
      ),
    );

    await expectLater(
      client.getJson('/bookings'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.unauthenticated,
        ),
      ),
    );
    expect(session.rejected, isTrue);
    expect(session.token, isNull);
  });

  test('POST wysyła JSON z nagłówkiem Content-Type', () async {
    final FakeSession session = FakeSession();
    String? body;
    String? contentType;
    final ApiClient client = clientFor(session, (http.Request request) {
      body = request.body;
      contentType = request.headers['Content-Type'];
      return ok(<String, Object?>{
        'data': <String, Object?>{'token': '1|x'},
      });
    });

    final Map<String, Object?> data = await client.postJson(
      '/auth/login',
      body: <String, Object?>{'email': 'a@b.pl', 'password': 'tajne'},
    );

    expect(jsonDecode(body!), <String, Object?>{
      'email': 'a@b.pl',
      'password': 'tajne',
    });
    expect(contentType, contains('application/json'));
    expect(data['token'], '1|x');
  });

  test('odpowiedź bez ciała (204) daje pustą mapę', () async {
    final FakeSession session = FakeSession(token: '7|abc');
    final ApiClient client = clientFor(
      session,
      (http.Request request) => http.Response('', 204),
    );

    expect(await client.deleteJson('/account/devices/01AB'), isEmpty);
  });
}
