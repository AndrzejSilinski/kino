// Sekcja powiadomień na ekranie konta: sześć stanów i dwie akcje.
//
// Najważniejsze sprawdzenie w tym pliku: stany, w których NIE DA SIĘ nic
// zrobić (serwer nie wysyła, urządzenie nie obsługuje, system odmówił na
// stałe), wyglądają inaczej niż „wyłączone" i mówią, co dalej. Jeden
// przełącznik na wszystko zamieniłby je w przycisk, który po naciśnięciu wraca
// na miejsce — bez słowa wyjaśnienia (decyzja 356).

import 'package:cinema/core/push.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/features/account/push_settings.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fake_push_service.dart';
import '../fixtures/push_api.dart';

const Timeout limit = Timeout(Duration(seconds: 30));

Widget sectionWith(
  FakePushApi api,
  FakePushService service, {
  required SecureStore store,
}) => ProviderScope(
  retry: noRetry,
  overrides: [
    httpClientProvider.overrideWithValue(
      MockClient((http.Request request) async => api.handle(request)),
    ),
    secureStoreProvider.overrideWithValue(store),
    sessionProvider.overrideWithValue(AppSession(store)),
    pushServiceProvider.overrideWithValue(service),
  ],
  child: const MaterialApp(
    home: Scaffold(
      body: Padding(padding: EdgeInsets.all(16), child: PushSettings()),
    ),
  ),
);

void main() {
  testWidgets(
    'serwer bez kanału push: zamiast przycisku wyjaśnienie',
    timeout: limit,
    (WidgetTester tester) async {
      final FakePushService service = FakePushService();
      addTearDown(service.dispose);
      await tester.pumpWidget(
        sectionWith(
          FakePushApi(config: 'client_config_push_off'),
          service,
          store: InMemorySecureStore(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.textContaining('Kino nie wysyła'), findsOneWidget);
      // Obietnica, że wiadomość i tak dotrze — inaczej ten ekran zostawia
      // klienta z wrażeniem, że o odwołanym seansie się nie dowie.
      expect(find.textContaining('e-mail'), findsOneWidget);
      expect(find.text('Włącz powiadomienia'), findsNothing);
    },
  );

  testWidgets(
    'telefon bez obsługi powiadomień mówi to wprost',
    timeout: limit,
    (WidgetTester tester) async {
      final FakePushService service = FakePushService(supported: false);
      addTearDown(service.dispose);
      await tester.pumpWidget(
        sectionWith(FakePushApi(), service, store: InMemorySecureStore()),
      );
      await tester.pumpAndSettle();

      expect(find.textContaining('nie obsługuje powiadomień'), findsOneWidget);
      expect(find.text('Włącz powiadomienia'), findsNothing);
    },
  );

  testWidgets('włączenie rejestruje urządzenie', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    final FakePushService service = FakePushService();
    addTearDown(service.dispose);
    await tester.pumpWidget(sectionWith(api, service, store: store));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Włącz powiadomienia'));
    await tester.pumpAndSettle();

    expect(api.registrations, hasLength(1));
    expect(api.consentCalls, 1);
    expect((await stored(store))!['token'], 'tokenFcmTestowy');
    expect(find.text('Wyłącz na tym telefonie'), findsOneWidget);
  });

  testWidgets('odmowa na stałe kieruje do ustawień telefonu', timeout: limit, (
    WidgetTester tester,
  ) async {
    // Tu przycisk „spróbuj jeszcze raz" byłby oszustwem: system drugi raz
    // okna nie pokaże.
    final FakePushApi api = FakePushApi();
    final FakePushService service = FakePushService(
      afterRequest: PushPermission.permanentlyDenied,
    );
    addTearDown(service.dispose);
    await tester.pumpWidget(
      sectionWith(api, service, store: InMemorySecureStore()),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Włącz powiadomienia'));
    await tester.pumpAndSettle();

    expect(find.textContaining('ustawieniach telefonu'), findsOneWidget);
    expect(find.text('Włącz powiadomienia'), findsNothing);
    expect(api.registrations, isEmpty);
  });

  testWidgets('włączone da się wyłączyć na tym telefonie', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    await remember(store, token: 'tokenFcmTestowy');
    final FakePushService service = FakePushService(
      systemPermission: PushPermission.granted,
    );
    addTearDown(service.dispose);
    await tester.pumpWidget(sectionWith(api, service, store: store));
    await tester.pumpAndSettle();
    expect(find.textContaining('Włączone na tym telefonie'), findsOneWidget);

    await tester.tap(find.text('Wyłącz na tym telefonie'));
    await tester.pumpAndSettle();

    expect(api.deletions, <String>[deviceId]);
    expect(await stored(store), isNull);
    // Zgoda na koncie ZOSTAJE — dotyczy też innych urządzeń (decyzja 352).
    expect(api.consentCalls, 0);
    expect(find.text('Włącz powiadomienia'), findsOneWidget);
  });

  testWidgets(
    'błąd serwera pokazuje się przy sekcji i da się go schować',
    timeout: limit,
    (WidgetTester tester) async {
      final FakePushApi api = FakePushApi(deviceStatus: 429);
      final InMemorySecureStore store = InMemorySecureStore();
      await remember(store, token: 'tokenFcmTestowy');
      final FakePushService service = FakePushService(
        systemPermission: PushPermission.granted,
      );
      addTearDown(service.dispose);
      await tester.pumpWidget(sectionWith(api, service, store: store));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Wyłącz na tym telefonie'));
      await tester.pumpAndSettle();

      expect(find.text('Za dużo żądań. Spróbuj za chwilę.'), findsOneWidget);

      await tester.tap(find.byTooltip('Zamknij komunikat powiadomień'));
      await tester.pumpAndSettle();

      expect(find.text('Za dużo żądań. Spróbuj za chwilę.'), findsNothing);
    },
  );
}
