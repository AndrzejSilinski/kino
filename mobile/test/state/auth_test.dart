// Logowanie od strony stanu: token trafia do magazynu, błędy serwera
// zostają w stanie z kodem, a wylogowanie czyści wszystko także wtedy,
// gdy serwer nie odpowiada.

import 'dart:convert';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, Object?> userPayload() => <String, Object?>{
  'id': 13,
  'name': 'Andrzej',
  'email': 'klient@example.com',
  'avatar_url': null,
  'role': 'customer',
  'role_label': 'Klient',
  'created_at': '2026-08-01T12:00:00+02:00',
};

http.Response json(Object body, int status) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

ProviderContainer containerWith({
  required InMemorySecureStore store,
  required http.Response Function(http.Request request) handler,
}) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      secureStoreProvider.overrideWithValue(store),
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => handler(request)),
      ),
    ],
  );
  addTearDown(container.dispose);
  return container;
}

void main() {
  test('udane logowanie zapisuje token i ustawia konto', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    final ProviderContainer container = containerWith(
      store: store,
      handler: (http.Request request) => json(<String, Object?>{
        'data': <String, Object?>{
          'user': userPayload(),
          'token': '15|sekretny-token',
          'token_type': 'Bearer',
        },
      }, 200),
    );

    final bool ok = await container
        .read(authProvider.notifier)
        .login(email: 'klient@example.com', password: 'tajne-haslo1');

    expect(ok, isTrue);
    expect(container.read(authProvider).isAuthenticated, isTrue);
    expect(container.read(authProvider).user?.name, 'Andrzej');
    expect(await store.read(StoreKeys.token), '15|sekretny-token');
  });

  test('błędne dane zostawiają kod błędu w stanie', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    final ProviderContainer container = containerWith(
      store: store,
      handler: (http.Request request) => json(<String, Object?>{
        'message': 'Nieprawidłowy adres e-mail lub hasło.',
        'code': 'INVALID_CREDENTIALS',
      }, 401),
    );

    final bool ok = await container
        .read(authProvider.notifier)
        .login(email: 'klient@example.com', password: 'zle');

    expect(ok, isFalse);
    final AuthState state = container.read(authProvider);
    expect(state.status, AuthStatus.anonymous);
    expect(state.error?.code, ApiError.invalidCredentials);
    expect(await store.read(StoreKeys.token), isNull);
  });

  test(
    'start aplikacji z zapisanym tokenem potwierdza konto na serwerze',
    () async {
      final InMemorySecureStore store = InMemorySecureStore();
      await store.write(StoreKeys.token, '15|sekretny-token');
      String? sentAuthorization;
      final ProviderContainer container = containerWith(
        store: store,
        handler: (http.Request request) {
          sentAuthorization = request.headers['Authorization'];
          return json(<String, Object?>{'data': userPayload()}, 200);
        },
      );

      await container.read(authProvider.notifier).restore();

      expect(sentAuthorization, 'Bearer 15|sekretny-token');
      expect(container.read(authProvider).isAuthenticated, isTrue);
    },
  );

  test('wylogowanie czyści token nawet bez odpowiedzi serwera', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    await store.write(StoreKeys.token, '15|sekretny-token');
    final ProviderContainer container = containerWith(
      store: store,
      handler: (http.Request request) => json(<String, Object?>{
        'message': 'Błąd.',
        'code': 'SERVER_ERROR',
      }, 500),
    );
    await container.read(authProvider.notifier).restore();

    await container.read(authProvider.notifier).logout();

    expect(container.read(authProvider).status, AuthStatus.anonymous);
    expect(await store.read(StoreKeys.token), isNull);
  });
}
