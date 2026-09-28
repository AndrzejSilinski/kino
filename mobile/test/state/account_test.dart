// Stan konta: gdzie ląduje zmieniony profil i co widzi użytkownik.
//
// Najważniejsze sprawdzenie w tym pliku: każda udana zmiana trafia do STANU
// LOGOWANIA (decyzja 331). Gdyby ekran konta trzymał własną kopię użytkownika,
// po zmianie nazwy kółko z inicjałem w nagłówku pokazywałoby starą literę —
// i żaden test jednego ekranu by tego nie złapał.

import 'dart:convert';

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/state/account.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const String token = '5|tokenTestowyKonta';

Map<String, Object?> userEnvelope({String name = 'Andrzej', String? avatar}) =>
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

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

class FakeAccountApi {
  FakeAccountApi({this.onProfile, this.onPassword, this.revoked = 0});

  final http.Response Function(String name)? onProfile;
  final http.Response Function()? onPassword;
  final int revoked;

  int profileCalls = 0;
  int passwordCalls = 0;
  int avatarPosts = 0;
  int avatarDeletes = 0;

  http.Response handle(http.Request request) {
    final String path = request.url.path;
    if (path.endsWith('/auth/me')) {
      return reply(userEnvelope());
    }
    if (path.endsWith('/account/profile')) {
      profileCalls++;
      final String name =
          (jsonDecode(request.body) as Map<String, Object?>)['name']! as String;
      return onProfile?.call(name) ?? reply(userEnvelope(name: name));
    }
    if (path.endsWith('/account/password')) {
      passwordCalls++;
      return onPassword?.call() ??
          reply(<String, Object?>{
            'data': <String, Object?>{
              'message': 'Hasło zostało zmienione.',
              'revoked_tokens': revoked,
            },
          });
    }
    if (request.method == 'DELETE') {
      avatarDeletes++;
      return reply(userEnvelope());
    }
    avatarPosts++;
    return reply(
      userEnvelope(avatar: 'http://localhost:8080/storage/avatars/abc.jpg'),
    );
  }
}

Future<ProviderContainer> loggedIn(FakeAccountApi api) async {
  final AppSession session = AppSession(InMemorySecureStore());
  await session.setToken(token);
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
      sessionProvider.overrideWithValue(session),
    ],
  );
  addTearDown(container.dispose);
  // Stan logowania potwierdza się na serwerze (`/auth/me`) już w `build`,
  // więc czekamy na to potwierdzenie, zamiast zakładać, że przyszło.
  for (int i = 0; i < 40; i++) {
    if (container.read(authProvider).status == AuthStatus.authenticated) {
      break;
    }
    await Future<void>.delayed(Duration.zero);
  }
  expect(container.read(authProvider).status, AuthStatus.authenticated);
  return container;
}

AccountController controller(ProviderContainer container) =>
    container.read(accountProvider.notifier);

AccountState state(ProviderContainer container) =>
    container.read(accountProvider);

void main() {
  test('zmieniona nazwa ląduje w stanie LOGOWANIA', () async {
    final FakeAccountApi api = FakeAccountApi();
    final ProviderContainer container = await loggedIn(api);

    final bool ok = await controller(container).changeName('  Andrzej S.  ');

    expect(ok, isTrue);
    expect(api.profileCalls, 1);
    // Spacje obcinamy u siebie, żeby nie wysyłać czegoś, co serwer i tak
    // obetnie — inaczej odpowiedź różniłaby się od tego, co wpisał klient.
    expect(container.read(authProvider).user!.name, 'Andrzej S.');
    expect(state(container).notice, 'Nazwa została zmieniona.');
    expect(state(container).busy, isFalse);
  });

  test('zmiana hasła mówi, ile INNYCH urządzeń wylogowano', () async {
    final FakeAccountApi api = FakeAccountApi(revoked: 2);
    final ProviderContainer container = await loggedIn(api);

    await controller(container)
        .changePassword(current: 'stareHaslo1', next: 'noweHaslo2');

    expect(api.passwordCalls, 1);
    expect(state(container).notice, contains('Hasło zostało zmienione.'));
    expect(state(container).notice, contains('Wylogowano 2 innych urządzeń'));
  });

  test('jedno urządzenie odmienia się w liczbie pojedynczej', () async {
    final ProviderContainer container = await loggedIn(
      FakeAccountApi(revoked: 1),
    );

    await controller(container)
        .changePassword(current: 'stareHaslo1', next: 'noweHaslo2');

    expect(state(container).notice, contains('1 inne urządzenie'));
  });

  test('bez innych urządzeń pokazujemy sam komunikat serwera', () async {
    final ProviderContainer container = await loggedIn(FakeAccountApi());

    await controller(container)
        .changePassword(current: 'stareHaslo1', next: 'noweHaslo2');

    expect(state(container).notice, 'Hasło zostało zmienione.');
  });

  test('złe obecne hasło zostaje jako błąd POLA', () async {
    // Komunikat ogólny („coś się nie udało”) zmusiłby klienta do zgadywania,
    // które z dwóch pól jest nie tak.
    final FakeAccountApi api = FakeAccountApi(
      onPassword: () => reply(<String, Object?>{
        'message': 'Podane dane są nieprawidłowe.',
        'code': 'VALIDATION_FAILED',
        'errors': <String, Object?>{
          'current_password': <String>['Obecne hasło jest nieprawidłowe.'],
        },
      }, 422),
    );
    final ProviderContainer container = await loggedIn(api);

    final bool ok = await controller(container)
        .changePassword(current: 'zleHaslo1', next: 'noweHaslo2');

    expect(ok, isFalse);
    expect(state(container).notice, isNull);
    expect(
      state(container).error!.fieldError('current_password'),
      'Obecne hasło jest nieprawidłowe.',
    );
    expect(state(container).busy, isFalse);
  });

  test('wgrany avatar ląduje w stanie logowania', () async {
    final FakeAccountApi api = FakeAccountApi();
    final ProviderContainer container = await loggedIn(api);

    await controller(container).uploadAvatar(const <int>[0xFF, 0xD8, 1, 2]);

    expect(api.avatarPosts, 1);
    expect(
      container.read(authProvider).user!.avatarUrl,
      endsWith('/avatars/abc.jpg'),
    );
    expect(state(container).notice, 'Zdjęcie zostało zapisane.');
  });

  test('usunięty avatar wraca do inicjału', () async {
    final FakeAccountApi api = FakeAccountApi();
    final ProviderContainer container = await loggedIn(api);
    await controller(container).uploadAvatar(const <int>[0xFF, 0xD8]);

    await controller(container).removeAvatar();

    expect(api.avatarDeletes, 1);
    expect(container.read(authProvider).user!.avatarUrl, isNull);
    expect(container.read(authProvider).user!.initial, 'A');
  });

  test('druga operacja w trakcie pierwszej nie rusza serwera', () async {
    // Ekran konta ma trzy przyciski; podwójne stuknięcie albo zapis nazwy
    // w trakcie wgrywania zdjęcia zużywałyby limit `account` bez powodu.
    final FakeAccountApi api = FakeAccountApi();
    final ProviderContainer container = await loggedIn(api);

    final Future<bool> pierwsza = controller(container).changeName('Pierwsza');
    final Future<bool> druga = controller(container).changeName('Druga');

    expect(await pierwsza, isTrue);
    expect(await druga, isFalse);
    expect(api.profileCalls, 1);
    expect(container.read(authProvider).user!.name, 'Pierwsza');
  });

  test('komunikat da się schować', () async {
    final ProviderContainer container = await loggedIn(FakeAccountApi());
    await controller(container).changeName('Andrzej S.');
    expect(state(container).notice, isNotNull);

    controller(container).dismiss();

    expect(state(container).notice, isNull);
    expect(state(container).error, isNull);
  });
}
