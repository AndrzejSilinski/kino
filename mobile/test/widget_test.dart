// Test startu aplikacji: to, co uruchamia main(), musi dać się zbudować
// w teście. Sam ekran diagnostyczny ma własne testy stanów
// (test/features/diagnostics_screen_test.dart).

import 'package:cinema/app.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('aplikacja startuje na ekranie diagnostycznym', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        retry: noRetry,
        overrides: [
          clientConfigProvider.overrideWith(
            (Ref ref) async => const ClientConfig(
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
            ),
          ),
        ],
        child: const CinemaApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Kino — diagnostyka'), findsOneWidget);
    expect(find.text('Połączenie z API działa'), findsOneWidget);
  });
}
