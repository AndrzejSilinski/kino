// Droga użytkownika: ekran główny -> logowanie -> powrót na ekran główny.
// Testy idą przez prawdziwy router i prawdziwy klient API (atrapa jest tylko
// na poziomie gniazda HTTP), więc sprawdzają to, co zobaczy użytkownik.

import 'dart:convert';

import 'package:cinema/app.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
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

Widget appWith(
  InMemorySecureStore store,
  http.Response Function(http.Request request) handler,
) => ProviderScope(
  retry: noRetry,
  overrides: [
    secureStoreProvider.overrideWithValue(store),
    httpClientProvider.overrideWithValue(
      MockClient((http.Request request) async => handler(request)),
    ),
  ],
  child: const CinemaApp(),
);

Future<void> openLogin(WidgetTester tester) async {
  await tester.pumpAndSettle();
  await tester.tap(find.widgetWithText(FilledButton, 'Zaloguj się'));
  await tester.pumpAndSettle();
  await tester.enterText(
    find.widgetWithText(TextFormField, 'Adres e-mail'),
    'klient@example.com',
  );
  await tester.enterText(
    find.widgetWithText(TextFormField, 'Hasło'),
    'tajne-haslo1',
  );
}

void main() {
  testWidgets('udane logowanie wraca na ekran główny i wita imieniem', (
    WidgetTester tester,
  ) async {
    final InMemorySecureStore store = InMemorySecureStore();
    await tester.pumpWidget(
      appWith(
        store,
        (http.Request request) => json(<String, Object?>{
          'data': <String, Object?>{
            'user': userPayload(),
            'token': '15|sekretny-token',
            'token_type': 'Bearer',
          },
        }, 200),
      ),
    );
    await openLogin(tester);

    await tester.tap(find.widgetWithText(FilledButton, 'Zaloguj się'));
    await tester.pumpAndSettle();

    expect(find.text('Cześć, Andrzej!'), findsOneWidget);
    expect(await store.read(StoreKeys.token), '15|sekretny-token');
  });

  testWidgets('błędne dane pokazują komunikat serwera, bez wychodzenia', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      appWith(
        InMemorySecureStore(),
        (http.Request request) => json(<String, Object?>{
          'message': 'Nieprawidłowy adres e-mail lub hasło.',
          'code': 'INVALID_CREDENTIALS',
        }, 401),
      ),
    );
    await openLogin(tester);

    await tester.tap(find.widgetWithText(FilledButton, 'Zaloguj się'));
    await tester.pumpAndSettle();

    expect(find.text('Nieprawidłowy adres e-mail lub hasło.'), findsOneWidget);
    expect(find.text('Logowanie'), findsOneWidget);
  });

  testWidgets('422 pokazuje komunikat przy polu formularza', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      appWith(
        InMemorySecureStore(),
        (http.Request request) => json(<String, Object?>{
          'message': 'Podane dane są nieprawidłowe.',
          'code': 'VALIDATION_FAILED',
          'errors': <String, Object?>{
            'email': <String>['Pole adres e-mail jest wymagane.'],
          },
        }, 422),
      ),
    );
    await openLogin(tester);

    await tester.tap(find.widgetWithText(FilledButton, 'Zaloguj się'));
    await tester.pumpAndSettle();

    expect(find.text('Pole adres e-mail jest wymagane.'), findsOneWidget);
  });
}
