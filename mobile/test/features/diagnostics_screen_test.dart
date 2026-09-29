// Widgety tylko pokazują dane i wołają providery, więc testy sprawdzają trzy
// stany ekranu: czekanie, dane z serwera oraz błąd z kodem i ponowną próbę.
// Żaden test nie dotyka sieci — provider jest podmieniany (overrides).

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/features/diagnostics/diagnostics_screen.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fake_push_service.dart';

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

/// Ta sama konfiguracja, ale z blokiem `push.android` z bloku K.
ClientConfig configFrom(String projectId) => ClientConfig(
  apiVersion: sampleConfig.apiVersion,
  realtime: sampleConfig.realtime,
  booking: sampleConfig.booking,
  pushEnabled: true,
  android: AndroidPushConfig(
    projectId: projectId,
    appId: '1:000000000000:android:1111111111111111111111',
    packageName: 'pl.silinski.cinema',
  ),
);

/// Ekran z konfiguracją serwera i projektem „wkompilowanym w APK".
Widget diagnosticsWith(ClientConfig config, FakePushService service) =>
    ProviderScope(
      retry: noRetry,
      overrides: [
        clientConfigProvider.overrideWith((Ref ref) async => config),
        pushServiceProvider.overrideWithValue(service),
      ],
      child: screenUnderTest,
    );

const String mismatchWarning = 'Aplikację zbudowano z INNEGO projektu';

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

  testWidgets('zgodny projekt Firebase nie zgłasza niczego', (
    WidgetTester tester,
  ) async {
    final FakePushService service = FakePushService()
      ..projectValue = const PushProject(
        projectId: 'kino-test',
        appId: '1:000000000000:android:1111111111111111111111',
      );
    addTearDown(service.dispose);

    await tester.pumpWidget(diagnosticsWith(configFrom('kino-test'), service));
    await tester.pumpAndSettle();

    expect(find.text('kino-test'), findsNWidgets(2));
    expect(find.textContaining(mismatchWarning), findsNothing);
  });

  testWidgets('INNY projekt Firebase w aplikacji niż na serwerze widać '
      'na ekranie', (WidgetTester tester) async {
    // To jedyne miejsce, w którym tę pomyłkę w ogóle widać: rejestracja
    // przechodzi, token wygląda poprawnie, a powiadomienia nie dochodzą
    // (decyzja 354).
    final FakePushService service = FakePushService()
      ..projectValue = const PushProject(
        projectId: 'kino-test',
        appId: '1:000000000000:android:1111111111111111111111',
      );
    addTearDown(service.dispose);

    await tester.pumpWidget(diagnosticsWith(configFrom('kino-prod'), service));
    await tester.pumpAndSettle();

    expect(find.textContaining(mismatchWarning), findsOneWidget);
  });

  testWidgets('APK bez google-services.json mówi to wprost', (
    WidgetTester tester,
  ) async {
    // Tu NIE ma niezgodności — jest brak konfiguracji, czyli zupełnie co
    // innego: push po prostu nie zadziała i widać dlaczego.
    final FakePushService service = FakePushService();
    addTearDown(service.dispose);

    await tester.pumpWidget(diagnosticsWith(configFrom('kino-prod'), service));
    await tester.pumpAndSettle();

    expect(find.textContaining('bez google-services.json'), findsOneWidget);
    expect(find.textContaining(mismatchWarning), findsNothing);
  });
}
