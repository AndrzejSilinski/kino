// Multipart: co dokładnie wychodzi na serwer przy wgrywaniu avatara.
//
// To jedyne żądanie w aplikacji, które nie jest JSON-em, więc warto sprawdzić
// je bajt po bajcie: nazwa pola, nazwa pliku, granica części i — co najważniejsze
// — że token nadal jedzie w nagłówku. `MockClient` „domyka” każde żądanie do
// zwykłego `Request` z surowymi bajtami ciała, więc da się to przeczytać
// bez ani jednego prawdziwego połączenia.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const String token = '9|tokenTestowyMultipart';
const List<int> jpeg = <int>[0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3, 250];

final List<http.Request> sent = <http.Request>[];

Future<ApiClient> clientFor(http.Response response) async {
  final AppSession session = AppSession(InMemorySecureStore());
  await session.setToken(token);
  return ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    session: session,
    httpClient: MockClient((http.Request request) async {
      sent.add(request);
      return response;
    }),
  );
}

http.Response json(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

Map<String, Object?> userEnvelope({String? avatar}) => <String, Object?>{
  'data': <String, Object?>{
    'id': 7,
    'name': 'Andrzej',
    'email': 'klient@example.com',
    'avatar_url': avatar,
    'role': 'customer',
    'role_label': 'Klient',
    'created_at': '2026-09-01T10:00:00+00:00',
  },
};

void main() {
  setUp(sent.clear);

  test('wysyła plik jako multipart z polem avatar', () async {
    final ApiClient client = await clientFor(
      json(userEnvelope(avatar: 'http://localhost:8080/storage/a.jpg')),
    );

    final Map<String, Object?> data = await client.postMultipart(
      '/account/avatar',
      field: 'avatar',
      filename: 'avatar.jpg',
      bytes: jpeg,
    );

    final http.Request request = sent.single;
    expect(request.method, 'POST');
    expect(request.url.path, '/api/v1/account/avatar');
    expect(
      request.headers['content-type'],
      startsWith('multipart/form-data; boundary='),
    );
    final String body = latin1.decode(request.bodyBytes);
    expect(body, contains('name="avatar"'));
    expect(body, contains('filename="avatar.jpg"'));
    // Bajty pliku idą w ciele bez zmian — 0xFF 0xD8 to nagłówek JPEG-a.
    expect(body, contains(latin1.decode(jpeg)));
    expect(data['name'], 'Andrzej');
  });

  test('token jedzie w nagłówku także przy multipart', () async {
    // Gdyby `postMultipart` składał nagłówki po swojemu, avatar byłby jedynym
    // żądaniem bez tokenu — i odpowiedzią byłoby 401 dopiero na telefonie.
    final ApiClient client = await clientFor(json(userEnvelope()));

    await client.postMultipart(
      '/account/avatar',
      field: 'avatar',
      filename: 'avatar.jpg',
      bytes: jpeg,
    );

    expect(sent.single.headers['Authorization'], 'Bearer $token');
    expect(sent.single.headers['Accept'], 'application/json');
  });

  test('odrzucony plik daje ApiError z komunikatem serwera', () async {
    final ApiClient client = await clientFor(
      json(<String, Object?>{
        'message': 'Avatar musi być plikiem JPG albo PNG.',
        'code': 'VALIDATION_FAILED',
        'errors': <String, Object?>{
          'avatar': <String>['Avatar musi być plikiem JPG albo PNG.'],
        },
      }, 422),
    );

    await expectLater(
      client.postMultipart(
        '/account/avatar',
        field: 'avatar',
        filename: 'avatar.jpg',
        bytes: jpeg,
      ),
      throwsA(
        isA<ApiError>()
            .having(
              (ApiError error) => error.message,
              'message',
              'Avatar musi być plikiem JPG albo PNG.',
            )
            .having(
              (ApiError error) => error.fieldError('avatar'),
              'błąd pola avatar',
              contains('JPG albo PNG'),
            ),
      ),
    );
  });
}
