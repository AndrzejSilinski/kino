// Repozytorium konta: adresy, ciała żądań i kształty odpowiedzi.
//
// Najważniejsze sprawdzenie: przy zmianie hasła wysyłamy `password_confirmation`.
// Reguła `confirmed` po stronie serwera wymaga OBECNOŚCI tego pola, a nie samej
// zgodności haseł — bez niego dostalibyśmy 422 na polu, którego użytkownik nawet
// nie widzi na ekranie.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/account_repository.dart';
import 'package:cinema/models/user.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

final List<http.Request> sent = <http.Request>[];

AccountRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => AccountRepository(
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

Map<String, Object?> userEnvelope({String? avatar, String name = 'Andrzej'}) =>
    <String, Object?>{
      'data': <String, Object?>{
        'id': 7,
        'name': name,
        'email': 'klient@example.com',
        'avatar_url': avatar,
        'role': 'customer',
        'role_label': 'Klient',
        'created_at': '2026-09-01T10:00:00+00:00',
      },
    };

void main() {
  setUp(sent.clear);

  test('zmiana nazwy idzie PATCH-em na profil', () async {
    final AccountRepository repository = repositoryFor(
      (http.Request request) => json(userEnvelope(name: 'Andrzej S.')),
    );

    final User user = await repository.updateName('Andrzej S.');

    expect(sent.single.method, 'PATCH');
    expect(sent.single.url.path, '/api/v1/account/profile');
    expect(jsonDecode(sent.single.body), <String, Object?>{
      'name': 'Andrzej S.',
    });
    expect(user.name, 'Andrzej S.');
  });

  test('zmiana hasła wysyła potwierdzenie i czyta liczbę wylogowań', () async {
    final AccountRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'data': <String, Object?>{
          'message': 'Hasło zostało zmienione.',
          'revoked_tokens': 2,
        },
      }),
    );

    final PasswordChanged result = await repository.changePassword(
      current: 'stareHaslo1',
      next: 'noweHaslo2',
    );

    expect(sent.single.method, 'PUT');
    expect(sent.single.url.path, '/api/v1/account/password');
    expect(jsonDecode(sent.single.body), <String, Object?>{
      'current_password': 'stareHaslo1',
      'password': 'noweHaslo2',
      // Bez tego pola serwer odpowiada 422 — reguła `confirmed`.
      'password_confirmation': 'noweHaslo2',
    });
    expect(result.message, 'Hasło zostało zmienione.');
    expect(result.revokedTokens, 2);
  });

  test('avatar idzie multipartem na podzasób konta', () async {
    final AccountRepository repository = repositoryFor(
      (http.Request request) =>
          json(userEnvelope(avatar: 'http://localhost:8080/storage/a.jpg')),
    );

    final User user = await repository.uploadAvatar(
      bytes: const <int>[0xFF, 0xD8, 1, 2],
    );

    expect(sent.single.method, 'POST');
    expect(sent.single.url.path, '/api/v1/account/avatar');
    expect(
      sent.single.headers['content-type'],
      startsWith('multipart/form-data'),
    );
    expect(user.avatarUrl, endsWith('/storage/a.jpg'));
  });

  test('usunięcie avatara oddaje profil z pustym adresem', () async {
    // Idempotentne: 200 także wtedy, gdy avatara nie było.
    final AccountRepository repository = repositoryFor(
      (http.Request request) => json(userEnvelope()),
    );

    final User user = await repository.removeAvatar();

    expect(sent.single.method, 'DELETE');
    expect(sent.single.url.path, '/api/v1/account/avatar');
    expect(user.avatarUrl, isNull);
    expect(user.initial, 'A');
  });
}
