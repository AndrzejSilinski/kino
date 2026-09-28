// Podpis kanału prywatnego. Kształt żądania i odpowiedzi potwierdzony
// rozpoznaniem fazy 2: wysyłamy `channel_name` i `socket_id`, a w odpowiedzi
// przychodzi SUROWE `{"auth": "..."}` — jedyny endpoint w API bez koperty.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/broadcast_auth_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

final List<http.Request> sent = <http.Request>[];

BroadcastAuthRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => BroadcastAuthRepository(
  ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async {
      sent.add(request);
      return handler(request);
    }),
  ),
);

http.Response json(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

void main() {
  setUp(sent.clear);

  test('wysyła kanał i socket_id, oddaje podpis', () async {
    final BroadcastAuthRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{'auth': 'klucz:podpis'}),
    );

    final String auth = await repository.authorize(
      'private-screenings.380',
      '45047736.917212264',
    );

    expect(auth, 'klucz:podpis');
    expect(sent.single.method, 'POST');
    expect(sent.single.url.path, '/api/v1/broadcasting/auth');
    expect(jsonDecode(sent.single.body), <String, Object?>{
      'channel_name': 'private-screenings.380',
      'socket_id': '45047736.917212264',
    });
  });

  test('odmowa dostępu do kanału dochodzi jako błąd z kodem', () async {
    final BroadcastAuthRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'message': 'Brak dostępu do tego kanału.',
        'code': 'FORBIDDEN',
      }, 403),
    );

    await expectLater(
      repository.authorize('private-bookings.01K6', '1.2'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.forbidden,
        ),
      ),
    );
  });

  test('odpowiedź bez pola auth to błąd kontraktu, nie pusty podpis', () async {
    final BroadcastAuthRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{'podpis': 'x'}),
    );

    await expectLater(
      repository.authorize('private-screenings.380', '1.2'),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.invalidResponse,
        ),
      ),
    );
  });
}
