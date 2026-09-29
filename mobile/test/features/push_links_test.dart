// Kliknięcie w powiadomienie: dokąd prowadzi i dokąd NIE prowadzi.
//
// Test montuje PRAWDZIWY korzeń aplikacji (`CinemaApp`) z podmienionym
// routerem. To nie jest ozdoba: gdyby ktoś kiedyś wyjął `PushLinks`
// z `builder`, powiadomienia przestałyby cokolwiek otwierać, a żaden test
// samego widgetu by tego nie zauważył (decyzja 357).

import 'package:cinema/app.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fake_push_service.dart';

const Timeout limit = Timeout(Duration(seconds: 30));
const String reference = '01M2R0FF9TF8JNNQ9GBNZJ3TDQ';

/// Trasy zastępcze: te same adresy co w aplikacji, ale bez ekranów, które
/// rozmawiają z API — sprawdzamy nawigację, a nie zawartość ekranu biletu.
GoRouter testRouter() => GoRouter(
  initialLocation: Routes.home,
  routes: <RouteBase>[
    GoRoute(
      path: Routes.home,
      builder: (BuildContext context, GoRouterState state) =>
          const Text('ekran startowy'),
    ),
    GoRoute(
      path: '${Routes.bookings}/:reference',
      builder: (BuildContext context, GoRouterState state) =>
          Text('bilet ${state.pathParameters['reference']}'),
    ),
  ],
);

Widget appWith(FakePushService service) => ProviderScope(
  retry: noRetry,
  overrides: [
    pushServiceProvider.overrideWithValue(service),
    secureStoreProvider.overrideWithValue(InMemorySecureStore()),
    routerProvider.overrideWithValue(testRouter()),
    httpClientProvider.overrideWithValue(
      MockClient(
        (http.Request request) async =>
            throw StateError('ten test nie rozmawia z siecią: ${request.url}'),
      ),
    ),
  ],
  child: const CinemaApp(),
);

PushNotification pushTo(String url) => PushNotification.fromData(
  <String, Object?>{'type': 'booking.paid', 'url': url},
  title: 'Płatność przyjęta',
  body: 'Skazani na Shawshank, jutro 18:30',
);

void main() {
  testWidgets('kliknięcie w powiadomienie otwiera rezerwację', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakePushService service = FakePushService();
    addTearDown(service.dispose);
    await tester.pumpWidget(appWith(service));
    await tester.pumpAndSettle();
    expect(find.text('ekran startowy'), findsOneWidget);

    service.emitOpened(pushTo('/bookings/$reference'));
    await tester.pumpAndSettle();

    expect(find.text('bilet $reference'), findsOneWidget);
  });

  testWidgets(
    'powiadomienie, którym URUCHOMIONO aplikację, też prowadzi '
    'do biletu',
    timeout: limit,
    (WidgetTester tester) async {
      // Aplikacja była zamknięta: wiadomość nie przychodzi strumieniem, tylko
      // czeka na odczyt przy starcie.
      final FakePushService service = FakePushService()
        ..launch = pushTo('/bookings/$reference');
      addTearDown(service.dispose);

      await tester.pumpWidget(appWith(service));
      await tester.pumpAndSettle();

      expect(find.text('bilet $reference'), findsOneWidget);
    },
  );

  testWidgets('nieznany adres tylko otwiera aplikację', timeout: limit, (
    WidgetTester tester,
  ) async {
    // Kształt poprawny, ale takiego ekranu nie ma. Router pokazałby wtedy
    // własny ekran błędu — czyli kliknięcie w powiadomienie kończyłoby się
    // komunikatem o błędzie (decyzja 355).
    final FakePushService service = FakePushService();
    addTearDown(service.dispose);
    await tester.pumpWidget(appWith(service));
    await tester.pumpAndSettle();

    service.emitOpened(pushTo('/czego-tu-nie-ma'));
    await tester.pumpAndSettle();

    expect(find.text('ekran startowy'), findsOneWidget);
  });

  testWidgets('adres spoza aplikacji nie prowadzi nigdzie', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakePushService service = FakePushService();
    addTearDown(service.dispose);
    await tester.pumpWidget(appWith(service));
    await tester.pumpAndSettle();

    service.emitOpened(pushTo('https://kino.example.com/bookings/$reference'));
    await tester.pumpAndSettle();

    expect(find.text('ekran startowy'), findsOneWidget);
  });

  testWidgets(
    'na pierwszym planie pokazujemy powiadomienie sami',
    timeout: limit,
    (WidgetTester tester) async {
      // FCM nie pokazuje nic, gdy aplikacja jest na wierzchu — bez tego kroku
      // klient siedzący w aplikacji nie dowiedziałby się o odwołanym seansie.
      final FakePushService service = FakePushService();
      addTearDown(service.dispose);
      await tester.pumpWidget(appWith(service));
      await tester.pumpAndSettle();

      service.emitForeground(pushTo('/bookings/$reference'));
      await tester.pumpAndSettle();

      expect(service.displayed, hasLength(1));
      expect(service.displayed.single.title, 'Płatność przyjęta');
      // Samo przyjście powiadomienia niczego nie otwiera — to robi dopiero
      // kliknięcie w nie.
      expect(find.text('ekran startowy'), findsOneWidget);
    },
  );

  testWidgets(
    'ciche powiadomienie nie pokazuje pustego dymka',
    timeout: limit,
    (WidgetTester tester) async {
      final FakePushService service = FakePushService();
      addTearDown(service.dispose);
      await tester.pumpWidget(appWith(service));
      await tester.pumpAndSettle();

      service.emitForeground(
        PushNotification.fromData(<String, Object?>{
          'type': 'booking.paid',
          'url': '/bookings/$reference',
        }),
      );
      await tester.pumpAndSettle();

      expect(service.displayed, isEmpty);
    },
  );

  testWidgets(
    'kanał powiadomień przygotowany raz, przy starcie',
    timeout: limit,
    (WidgetTester tester) async {
      final FakePushService service = FakePushService();
      addTearDown(service.dispose);

      await tester.pumpWidget(appWith(service));
      await tester.pumpAndSettle();

      expect(service.prepared, 1);
    },
  );
}
