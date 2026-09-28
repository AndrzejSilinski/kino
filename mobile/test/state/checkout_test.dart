// Stan płatności bez Stripe'a i bez sieci: atrapa API, sterowane czasy.
//
// Najważniejszy test w tym pliku to ten o czekaniu na potwierdzenie serwera.
// PaymentSheet potrafi wrócić z sukcesem, zanim webhook Stripe'a dotrze do
// naszego serwera — aplikacja, która na podstawie własnego wyniku pokaże
// „kupione”, będzie czasem kłamać (decyzja 313).

import 'package:cinema/core/payment_sheet.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/booking_event.dart';
import 'package:cinema/state/checkout.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// `reply` i `testScreeningId` biorę z atrapy — jedno miejsce na atrapę serwera.
import '../fixtures/booking_api.dart';
import '../fixtures/fake_payment_sheet.dart';
import '../fixtures/fixtures.dart';

ProviderContainer containerFor(
  FakeBookingApi api, {
  FakePaymentSheet? sheet,
  CheckoutPolling polling = const CheckoutPolling(
    interval: Duration(milliseconds: 5),
    attempts: 4,
  ),
}) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
      // Odpytywanie w milisekundach zamiast pół minuty (nauczka z bloku G1).
      checkoutPollingProvider.overrideWithValue(polling),
      // Prawdziwy arkusz wymaga platformy natywnej (decyzja 315).
      paymentSheetProvider.overrideWithValue(sheet ?? FakePaymentSheet()),
    ],
  );
  addTearDown(container.dispose);
  return container;
}

CheckoutController controller(ProviderContainer container) =>
    container.read(checkoutProvider(testScreeningId).notifier);

CheckoutState state(ProviderContainer container) =>
    container.read(checkoutProvider(testScreeningId)).requireValue;

Future<void> open(ProviderContainer container) async {
  // Ekran musi być OBSERWOWANY, tak jak w aplikacji (pułapka DO).
  container.listen(
    checkoutProvider(testScreeningId),
    (AsyncValue<CheckoutState>? previous, AsyncValue<CheckoutState> next) {},
    fireImmediately: true,
  );
  await container.read(checkoutProvider(testScreeningId).future);
}

/// Koperta rezerwacji, która NADAL oczekuje na płatność.
///
/// Biorę ją z fikstury checkoutu, żeby nie trzymać czwartego pliku z tą samą
/// rezerwacją w innym stanie.
Map<String, Object?> pendingEnvelope() => <String, Object?>{
  'data': fixtureMap('checkout')['booking']! as Map<String, Object?>,
};

void main() {
  test('wejście na ekran NIE tworzy płatności', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(api);

    await open(container);

    expect(api.checkoutCalls, 0);
    expect(state(container).checkout, isNull);
    expect(state(container).canPay, isFalse);
  });

  test('rozpoczęcie płatności zapamiętuje rezerwację i dane Stripe', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(api);
    await open(container);

    await controller(container).start();

    expect(api.checkoutCalls, 1);
    final CheckoutState now = state(container);
    expect(now.checkout!.booking.reference, '01M3MK53H15CAMZB8DE9AFF06Q');
    expect(now.booking!.status, BookingStatus.pending);
    expect(now.canPay, isTrue);
    expect(now.busy, isFalse);
    expect(now.notice, isNull);
  });

  test('pusty koszyk kończy się komunikatem, nie wyjątkiem', () async {
    final FakeBookingApi api = FakeBookingApi(
      onCheckout: (int call) => reply(<String, Object?>{
        'message': 'Koszyk jest pusty.',
        'code': 'EMPTY_CART',
      }, 422),
    );
    final ProviderContainer container = containerFor(api);
    await open(container);

    await controller(container).start();

    expect(state(container).notice!.code, 'EMPTY_CART');
    expect(state(container).checkout, isNull);
    expect(container.read(checkoutProvider(testScreeningId)).hasError, isFalse);
  });

  test(
    '409 prowadzi do rozpoczętej rezerwacji, a nie w ślepy zaułek',
    () async {
      final FakeBookingApi api = FakeBookingApi(
        onCheckout: (int call) => reply(<String, Object?>{
          'message': 'Masz już rozpoczętą płatność za te miejsca.',
          'code': 'BOOKING_ALREADY_PENDING',
          'context': <String, Object?>{
            'booking_reference': '01M3MK53H15CAMZB8DE9AFF06Q',
          },
        }, 409),
      );
      final ProviderContainer container = containerFor(api);
      await open(container);

      await controller(container).start();
      await Future<void>.delayed(const Duration(milliseconds: 50));

      final CheckoutState now = state(container);
      expect(now.conflictReference, '01M3MK53H15CAMZB8DE9AFF06Q');
      expect(now.notice!.code, 'BOOKING_ALREADY_PENDING');
      // Aplikacja od razu pobiera tamtą rezerwację, żeby mieć co pokazać.
      expect(api.bookingCalls, 1);
      expect(now.booking, isNotNull);
    },
  );

  test('rezygnacja czyści płatność i zostawia anulowaną rezerwację', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();

    await controller(container).cancel();

    final CheckoutState now = state(container);
    expect(now.checkout, isNull);
    expect(now.booking!.status, BookingStatus.cancelled);
    expect(now.booking!.cancellation, isNotNull);
  });

  test('po płatności czekamy na POTWIERDZENIE SERWERA', () async {
    // Pierwsze dwa pytania: rezerwacja nadal oczekuje (webhook w drodze),
    // trzecie: opłacona. Dokładnie tak wygląda to na żywo.
    final FakeBookingApi api = FakeBookingApi(
      onBooking: (int call) =>
          reply(call < 3 ? pendingEnvelope() : envelope('booking_paid')),
    );
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();

    final Booking? booking = await controller(container).waitForPayment();

    expect(booking, isNotNull);
    expect(booking!.status, BookingStatus.paid);
    expect(api.bookingCalls, 3);
    final CheckoutState now = state(container);
    expect(now.waiting, isFalse);
    expect(now.isPaid, isTrue);
    expect(now.notice, isNull);
  });

  test('brak potwierdzenia w oknie NIE znaczy „nie zapłacono”', () async {
    final FakeBookingApi api = FakeBookingApi(
      onBooking: (int call) => reply(pendingEnvelope()),
    );
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();

    await controller(container).waitForPayment();

    final CheckoutState now = state(container);
    expect(now.waiting, isFalse);
    expect(now.isPaid, isFalse);
    expect(now.notice!.message, contains('jeszcze nie dotarło'));
    // Cztery próby z podmienionej konfiguracji odpytywania.
    expect(api.bookingCalls, 4);
  });

  test('komunikat da się schować', () async {
    final FakeBookingApi api = FakeBookingApi(
      onCheckout: (int call) => reply(<String, Object?>{
        'message': 'Koszyk jest pusty.',
        'code': 'EMPTY_CART',
      }, 422),
    );
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();
    expect(state(container).notice, isNotNull);

    controller(container).dismissNotice();

    expect(state(container).notice, isNull);
  });

  test('arkusz dostaje klucz i sekret Z SERWERA', () async {
    // Sedno decyzji 310: aplikacja nie zna klucza Stripe'a, tylko przekazuje
    // dalej to, co przyszło w odpowiedzi checkoutu.
    final FakePaymentSheet sheet = FakePaymentSheet();
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(api, sheet: sheet);
    await open(container);
    await controller(container).start();

    await controller(container).pay();

    expect(sheet.calls, 1);
    expect(sheet.publishableKey, 'pk_test_fikstura');
    expect(sheet.clientSecret, 'sekret-testowy-fikstura');
    expect(sheet.merchantName, paymentMerchantName);
  });

  test('powodzenie arkusza NIE kończy zakupu — pytamy serwer', () async {
    // Arkusz mówi „gotowe”, a webhook Stripe'a jest jeszcze w drodze: dopóki
    // serwer nie potwierdzi, nie wolno pokazać „kupione” (decyzja 313).
    final FakeBookingApi api = FakeBookingApi(
      onBooking: (int call) =>
          reply(call < 3 ? pendingEnvelope() : envelope('booking_paid')),
    );
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();

    final Booking? booking = await controller(container).pay();

    expect(booking!.status, BookingStatus.paid);
    expect(api.bookingCalls, 3);
    expect(state(container).isPaid, isTrue);
    expect(state(container).waiting, isFalse);
  });

  test('rezygnacja w arkuszu NIE jest błędem', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(
      api,
      sheet: FakePaymentSheet(
        outcome: const PaymentSheetOutcome(PaymentSheetResult.cancelled),
      ),
    );
    await open(container);
    await controller(container).start();

    final Booking? booking = await controller(container).pay();

    expect(booking, isNull);
    // Żadnego komunikatu o błędzie: klient sam zamknął arkusz.
    expect(state(container).notice, isNull);
    // Rezerwacja stoi dalej, więc można spróbować ponownie.
    expect(state(container).canPay, isTrue);
    expect(api.bookingCalls, 0);
  });

  test('odmowa karty pokazuje komunikat od Stripe', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(
      api,
      sheet: FakePaymentSheet(
        outcome: const PaymentSheetOutcome(
          PaymentSheetResult.failed,
          message: 'Twoja karta została odrzucona.',
        ),
      ),
    );
    await open(container);
    await controller(container).start();

    await controller(container).pay();

    expect(state(container).notice!.message, 'Twoja karta została odrzucona.');
    expect(state(container).busy, isFalse);
    // Nie pytamy serwera o potwierdzenie czegoś, co się nie zdarzyło.
    expect(api.bookingCalls, 0);
  });

  test('awaria bez komunikatu dostaje własny tekst', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(
      api,
      sheet: FakePaymentSheet(
        outcome: const PaymentSheetOutcome(PaymentSheetResult.failed),
      ),
    );
    await open(container);
    await controller(container).start();

    await controller(container).pay();

    expect(state(container).notice!.message, contains('Spróbuj ponownie'));
  });

  test('bez rozpoczętej płatności arkusz się nie otwiera', () async {
    final FakePaymentSheet sheet = FakePaymentSheet();
    final ProviderContainer container = containerFor(
      FakeBookingApi(),
      sheet: sheet,
    );
    await open(container);

    await controller(container).pay();

    expect(sheet.calls, 0);
  });

  test('zdarzenie z kanału kończy czekanie szybciej', () async {
    // Droga szybka (decyzja 318): ramka z kanału odświeża rezerwację, a pętla
    // odpytywania kończy się na tym stanie, bez kolejnego pytania.
    bool paid = false;
    final FakeBookingApi api = FakeBookingApi(
      onBooking: (int call) =>
          reply(paid ? envelope('booking_paid') : pendingEnvelope()),
    );
    final ProviderContainer container = containerFor(
      api,
      polling: const CheckoutPolling(
        interval: Duration(milliseconds: 60),
        attempts: 6,
      ),
    );
    await open(container);
    await controller(container).start();

    final Future<Booking?> pending = controller(container).waitForPayment();
    await Future<void>.delayed(const Duration(milliseconds: 10));
    paid = true;
    await controller(container).onBookingEvent(
      BookingStatusEvent.tryParse(<String, Object?>{
        'reference': '01M3MK53H15CAMZB8DE9AFF06Q',
        'status': 'paid',
        'status_label': 'Opłacona',
      })!,
    );
    final Booking? booking = await pending;

    expect(booking!.status, BookingStatus.paid);
    expect(state(container).isPaid, isTrue);
    expect(state(container).waiting, isFalse);
    // Jedno pytanie z pętli i jedno ze zdarzenia — trzeciego nie było.
    expect(api.bookingCalls, 2);
  });

  test('zdarzenie o INNEJ rezerwacji jest pomijane', () async {
    final FakeBookingApi api = FakeBookingApi();
    final ProviderContainer container = containerFor(api);
    await open(container);
    await controller(container).start();

    await controller(container).onBookingEvent(
      BookingStatusEvent.tryParse(<String, Object?>{
        'reference': '01CUDZAREZERWACJA0000000AB',
        'status': 'paid',
        'status_label': 'Opłacona',
      })!,
    );

    expect(api.bookingCalls, 0);
    expect(state(container).booking!.status, BookingStatus.pending);
  });
}
