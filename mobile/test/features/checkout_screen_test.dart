// Ekran płatności — tu klient naprawdę wydaje pieniądze, więc test przechodzi
// całą ścieżkę: podsumowanie, rozpoczęcie płatności, arkusz, potwierdzenie
// serwera, rezygnację, konflikt i wygaśnięcie okna.
//
// Arkusz Stripe'a to atrapa (decyzja 315), gniazdo to atrapa (pułapka DM),
// serwer to atrapa. Każdy test ma własny limit czasu, żeby potknięcie kosztowało
// pół minuty, a nie dziesięć minut (nauczka z bloków G1 i G2).

import 'dart:convert';

import 'package:cinema/core/payment_sheet.dart';
import 'package:cinema/core/realtime.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/features/booking/checkout_screen.dart';
import 'package:cinema/state/checkout.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/booking_api.dart';
import '../fixtures/fake_payment_sheet.dart';
import '../fixtures/fake_socket.dart';
import '../fixtures/fixtures.dart';

const Timeout limit = Timeout(Duration(seconds: 30));

Widget screenWith(
  FakeBookingApi api, {
  FakePaymentSheet? sheet,
  CheckoutPolling polling = const CheckoutPolling(
    interval: Duration(milliseconds: 5),
    attempts: 3,
  ),
}) => ProviderScope(
  retry: noRetry,
  overrides: [
    httpClientProvider.overrideWithValue(
      MockClient((http.Request request) async => api.handle(request)),
    ),
    secureStoreProvider.overrideWithValue(InMemorySecureStore()),
    realtimeClientProvider.overrideWithValue(
      AsyncData<RealtimeClient>(clientFor(SocketLog())),
    ),
    paymentSheetProvider.overrideWithValue(sheet ?? FakePaymentSheet()),
    checkoutPollingProvider.overrideWithValue(polling),
  ],
  child: const MaterialApp(home: CheckoutScreen(screeningId: testScreeningId)),
);

/// Koszyk z dwoma miejscami — punkt wyjścia większości testów.
FakeBookingApi apiWithCart({
  http.Response Function(int call)? onCheckout,
  http.Response Function(int call)? onBooking,
}) =>
    FakeBookingApi(onCheckout: onCheckout, onBooking: onBooking)
      ..cart = cartOf(<int>[102, 103]);

/// Rezerwacja, która NADAL oczekuje na płatność.
Map<String, Object?> pendingEnvelope() => <String, Object?>{
  'data': fixtureMap('checkout')['booking']! as Map<String, Object?>,
};

/// Rezerwacja w podanym statusie, z etykietą z serwera.
Map<String, Object?> statusEnvelope(String status, String label) {
  final Map<String, Object?> data = fixtureMap('booking_paid');
  data['status'] = status;
  data['status_label'] = label;
  data['paid_at'] = null;
  return <String, Object?>{'data': data};
}

/// Odpowiedź checkoutu z krótkim oknem płatności.
Map<String, Object?> checkoutWithWindow(int seconds) {
  final Map<String, Object?> envelope =
      jsonDecode(jsonEncode(fixtureMap('checkout'))) as Map<String, Object?>;
  (envelope['payment']! as Map<String, Object?>)['expires_in_seconds'] =
      seconds;
  return <String, Object?>{'data': envelope};
}

void main() {
  testWidgets(
    'pokazuje podsumowanie z koszyka i NIE tworzy płatności',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = apiWithCart();

      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      expect(find.text('Do zapłaty'), findsOneWidget);
      expect(find.text('50,60 zł'), findsOneWidget);
      expect(find.text('Miejsce A2'), findsOneWidget);
      expect(find.text('Miejsce A3'), findsOneWidget);
      // 540 sekund blokady z serwera to 9:00 na ekranie.
      expect(find.text('9:00'), findsOneWidget);
      expect(find.text('Przejdź do płatności'), findsOneWidget);
      // Sedno decyzji 314: obejrzenie ekranu nie tworzy intencji w Stripe.
      expect(api.checkoutCalls, 0);
      // Podsumowanie bierze sam koszyk, bez planu sali (setki foteli).
      expect(api.seatMapCalls, 0);
    },
  );

  testWidgets(
    'przejście do płatności pokazuje rezerwację i okno',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = apiWithCart();
      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Przejdź do płatności'));
      await tester.pumpAndSettle();

      expect(api.checkoutCalls, 1);
      expect(
        find.text('Rezerwacja 01M3MK53H15CAMZB8DE9AFF06Q'),
        findsOneWidget,
      );
      expect(find.text('Zapłać 43,70 zł'), findsOneWidget);
      // 598 sekund okna płatności z serwera to 9:58.
      expect(find.text('9:58'), findsOneWidget);
      expect(find.text('Zrezygnuj z płatności'), findsOneWidget);
    },
  );

  testWidgets('zapłata kończy się POTWIERDZENIEM SERWERA', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakePaymentSheet sheet = FakePaymentSheet();
    final FakeBookingApi api = apiWithCart(
      onBooking: (int call) =>
          reply(call < 2 ? pendingEnvelope() : envelope('booking_paid')),
    );
    await tester.pumpWidget(screenWith(api, sheet: sheet));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Przejdź do płatności'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Zapłać 43,70 zł'));
    await tester.pumpAndSettle();

    expect(sheet.calls, 1);
    // Arkusz powiedział „gotowe” po pierwszym pytaniu, ale ekran pokazał zakup
    // dopiero wtedy, gdy potwierdził go serwer (decyzja 313).
    expect(api.bookingCalls, greaterThanOrEqualTo(2));
    expect(find.text('Opłacona'), findsOneWidget);
    expect(find.text('43,70 zł'), findsOneWidget);
    expect(find.text('Wróć do repertuaru'), findsOneWidget);
  });

  testWidgets('rezygnacja w arkuszu NIE jest błędem', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = apiWithCart();
    await tester.pumpWidget(
      screenWith(
        api,
        sheet: FakePaymentSheet(
          outcome: const PaymentSheetOutcome(PaymentSheetResult.cancelled),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Przejdź do płatności'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Zapłać 43,70 zł'));
    await tester.pumpAndSettle();

    // Ekran zostaje na płatności, bez ani jednego komunikatu o błędzie.
    expect(find.text('Zapłać 43,70 zł'), findsOneWidget);
    expect(find.byTooltip('Zamknij'), findsNothing);
    expect(api.bookingCalls, 0);
  });

  testWidgets('odmowa karty pokazuje komunikat od Stripe', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = apiWithCart();
    await tester.pumpWidget(
      screenWith(
        api,
        sheet: FakePaymentSheet(
          outcome: const PaymentSheetOutcome(
            PaymentSheetResult.failed,
            message: 'Twoja karta została odrzucona.',
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Przejdź do płatności'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Zapłać 43,70 zł'));
    await tester.pumpAndSettle();

    expect(find.text('Twoja karta została odrzucona.'), findsOneWidget);
    // Komunikat da się zamknąć i zapłacić jeszcze raz.
    await tester.tap(find.byTooltip('Zamknij'));
    await tester.pumpAndSettle();
    expect(find.text('Twoja karta została odrzucona.'), findsNothing);
    expect(find.text('Zapłać 43,70 zł'), findsOneWidget);
  });

  testWidgets(
    'brak potwierdzenia w oknie NIE znaczy „nie zapłacono”',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = apiWithCart(
        onBooking: (int call) => reply(pendingEnvelope()),
      );
      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Przejdź do płatności'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Zapłać 43,70 zł'));
      await tester.pumpAndSettle();

      expect(find.textContaining('jeszcze nie dotarło'), findsOneWidget);
      // I ani słowa o tym, że nie zapłacono — bo tego nie wiemy.
      expect(find.text('Opłacona'), findsNothing);
      expect(find.textContaining('odrzucona'), findsNothing);
    },
  );

  testWidgets(
    '409 prowadzi do tamtej płatności, a nie w ślepy zaułek',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = apiWithCart(
        onCheckout: (int call) => reply(<String, Object?>{
          'message': 'Masz już rozpoczętą płatność za te miejsca.',
          'code': 'BOOKING_ALREADY_PENDING',
          'context': <String, Object?>{
            'booking_reference': '01M3MK53H15CAMZB8DE9AFF06Q',
          },
        }, 409),
        onBooking: (int call) => reply(pendingEnvelope()),
      );
      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Przejdź do płatności'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('jest już rozpoczęta pod numerem'),
        findsOneWidget,
      );
      expect(find.text('Zrezygnuj z płatności'), findsOneWidget);
      // Przycisku, który znów dostałby 409, po prostu nie ma.
      expect(find.text('Przejdź do płatności'), findsNothing);
      expect(api.bookingCalls, 1);
    },
  );

  testWidgets(
    'rezygnacja zwalnia miejsca i pokazuje status z serwera',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = apiWithCart();
      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Przejdź do płatności'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Zrezygnuj z płatności'));
      await tester.pumpAndSettle();

      expect(find.text('Anulowana'), findsOneWidget);
      expect(find.text('Wybierz miejsca ponownie'), findsOneWidget);
    },
  );

  testWidgets('wygaśnięcie okna płatności pokazuje prawdę', timeout: limit, (
    WidgetTester tester,
  ) async {
    // Licznik dochodzący do zera bez pobrania rezerwacji zostawiłby na ekranie
    // przycisk „Zapłać” do płatności, której serwer już nie przyjmie.
    final FakeBookingApi api = apiWithCart(
      onCheckout: (int call) => reply(checkoutWithWindow(2), 201),
      onBooking: (int call) => reply(statusEnvelope('expired', 'Wygasła')),
    );
    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Przejdź do płatności'));
    await tester.pumpAndSettle();
    expect(find.text('0:02'), findsOneWidget);

    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(find.text('Wygasła'), findsOneWidget);
    expect(find.textContaining('Zapłać'), findsNothing);
    expect(api.bookingCalls, 1);
  });

  testWidgets(
    'błąd pobierania koszyka daje przycisk ponowienia',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = FakeBookingApi()
        ..cart = <String, Object?>{
          'message': 'Nie znaleziono seansu.',
          'code': 'RESOURCE_NOT_FOUND',
        };

      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      // Atrapa oddaje ten kształt ze statusem 200, więc to błąd KONTRAKTU —
      // i ekran ma się z niego pozbierać tak samo jak z 404.
      expect(find.text('Spróbuj ponownie'), findsOneWidget);
      expect(find.text('Do zapłaty'), findsNothing);
    },
  );
}
