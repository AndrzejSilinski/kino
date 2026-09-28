// Widgety tylko pokazują dane i wołają providery, więc testy sprawdzają trzy
// stany ekranu: czekanie, dane z serwera oraz błąd z kodem i ponowną próbę.
// Żaden test nie dotyka sieci — provider jest podmieniany (overrides).

import 'package:cinema/core/api_error.dart';
import 'package:cinema/features/diagnostics/diagnostics_screen.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

const ClientConfig sampleConfig = ClientConfig(
  apiVersion: 'v1',
  realtime: RealtimeConfig(
    broadcaster: 'reverb',
    key: 'testowyKluczReverba',
    path: '/app',
  ),
  booking: BookingConfig(
    seatLockTtl: Duration(minutes: 10),
    maxSeatsPerSession: 10,
    paymentWindow: Duration(minutes: 10),
  ),
  pushEnabled: true,
);

const MaterialApp screenUnderTest = MaterialApp(home: DiagnosticsScreen());

void main() {
  testWidgets('pokazuje wskaźnik, dopóki konfiguracja nie przyjdzie', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        retry: noRetry,
        overrides: [
          clientConfigProvider.overrideWith(
            (Ref ref) => Future<ClientConfig>.delayed(
              const Duration(seconds: 5),
              () => sampleConfig,
            ),
          ),
        ],
        child: screenUnderTest,
      ),
    );
    await tester.pump();

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.text('Połączenie z API działa'), findsNothing);

    await tester.pump(const Duration(seconds: 6));
  });

  testWidgets('pokazuje parametry z serwera po udanym pobraniu', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        retry: noRetry,
        overrides: [
          clientConfigProvider.overrideWith((Ref ref) async => sampleConfig),
        ],
        child: screenUnderTest,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Połączenie z API działa'), findsOneWidget);
    expect(find.text('600 s'), findsNWidgets(2));
    expect(find.text('10'), findsOneWidget);
    expect(find.textContaining('klucz 19 znaków'), findsOneWidget);
  });

  testWidgets('pokazuje komunikat i kod błędu, a przycisk ponawia próbę', (
    WidgetTester tester,
  ) async {
    int attempts = 0;
    await tester.pumpWidget(
      ProviderScope(
        retry: noRetry,
        overrides: [
          clientConfigProvider.overrideWith((Ref ref) async {
            attempts++;
            if (attempts == 1) {
              throw ApiError.network('test');
            }
            return sampleConfig;
          }),
        ],
        child: screenUnderTest,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Brak połączenia z serwerem'), findsOneWidget);
    expect(find.text('Kod: ${ApiError.networkError}'), findsOneWidget);

    await tester.tap(find.text('Spróbuj ponownie'));
    await tester.pumpAndSettle();

    expect(attempts, 2);
    expect(find.text('Połączenie z API działa'), findsOneWidget);
  });
}
