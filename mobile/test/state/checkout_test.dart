// Stan płatności bez Stripe'a i bez sieci: atrapa API, sterowane czasy.
//
// Najważniejszy test w tym pliku to ten o czekaniu na potwierdzenie serwera.
// PaymentSheet potrafi wrócić z sukcesem, zanim webhook Stripe'a dotrze do
// naszego serwera — aplikacja, która na podstawie własnego wyniku pokaże
// „kupione”, będzie czasem kłamać (decyzja 313).

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/state/checkout.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// `reply` i `testScreeningId` biorę z atrapy — jedno miejsce na atrapę serwera.
import '../fixtures/booking_api.dart';
import '../fixtures/fixtures.dart';

ProviderContainer containerFor(FakeBookingApi api) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
      // Odpytywanie w milisekundach zamiast pół minuty (nauczka z bloku G1).
      checkoutPollingProvider.overrideWithValue(
        const CheckoutPolling(interval: Duration(milliseconds: 5), attempts: 4),
      ),
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
}
