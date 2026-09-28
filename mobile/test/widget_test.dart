// Test startu aplikacji: to, co uruchamia main(), musi dać się zbudować
// w teście i pokazać ekran główny. Ekran diagnostyczny i logowanie mają
// własne testy.

import 'dart:convert';

import 'package:cinema/app.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('aplikacja startuje na ekranie głównym dla gościa', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        retry: noRetry,
        overrides: [
          secureStoreProvider.overrideWithValue(InMemorySecureStore()),
          httpClientProvider.overrideWithValue(
            MockClient(
              (http.Request request) async => http.Response(
                jsonEncode(<String, Object?>{
                  'message': 'Wymagane jest zalogowanie.',
                  'code': 'UNAUTHENTICATED',
                }),
                401,
                headers: <String, String>{'content-type': 'application/json'},
              ),
            ),
          ),
        ],
        child: const CinemaApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Kino'), findsOneWidget);
    expect(find.text('Zaloguj się'), findsWidgets);
  });
}
