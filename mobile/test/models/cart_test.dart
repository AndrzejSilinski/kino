// Koszyk z prawdziwej odpowiedzi serwera (rozpoznanie fazy 2).
//
// Kwot nie liczymy w aplikacji: `total` i `formatted` przychodzą z serwera.
// Test pilnuje też tego, co łatwo przeoczyć — czasy blokad przychodzą w UTC,
// a nie w strefie kina (pułapka CY).

import 'package:cinema/models/cart.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

void main() {
  test('czyta miejsca, wycenę i czas do wygaśnięcia', () {
    final Cart cart = Cart.fromJson(fixtureMap('cart'));

    expect(cart.seatsCount, 1);
    expect(cart.seats.single.label, 'A3');
    expect(cart.seats.single.price.formatted, '25,30 zł');
    expect(cart.seats.single.category.name, 'Standardowe');
    expect(cart.total.amount, 2530);
    expect(cart.total.formatted, '25,30 zł');
    expect(cart.expiresInSeconds, 540);
    expect(cart.pendingBooking, isNull);
    expect(cart.isEmpty, isFalse);
    expect(cart.isConsistent, isTrue);
    expect(cart.seatIds, <int>{103});
  });

  test('czas blokady przychodzi w UTC, nie w strefie kina', () {
    final Cart cart = Cart.fromJson(fixtureMap('cart'));

    expect(cart.expiresAt!.offset, Duration.zero);
    // Ten sam moment w strefie kina to 17:40 — dlatego do odliczania służą
    // sekundy z serwera, a nie różnica dat liczona na telefonie.
    expect(cart.expiresAt!.time, '15:40');
  });

  test('pusty koszyk ma zero i brak terminu', () {
    final Cart cart = Cart.fromJson(fixtureMap('cart_empty'));

    expect(cart.isEmpty, isTrue);
    expect(cart.seats, isEmpty);
    expect(cart.total.amount, 0);
    expect(cart.expiresAt, isNull);
    expect(cart.expiresInSeconds, isNull);
    expect(cart.seatIds, isEmpty);
  });

  test('stała pustego koszyka nie wymaga żądania do serwera', () {
    expect(Cart.empty.isEmpty, isTrue);
    expect(Cart.empty.total.formatted, '0,00 zł');
  });

  test('rozpoczęta płatność jest widoczna w koszyku', () {
    final Map<String, Object?> data = fixtureMap('cart');
    data['pending_booking'] = <String, Object?>{
      'reference': '01K6ABCDEFGHJKMNPQRSTVWXYZ',
      'expires_at': '2026-09-28T15:45:52+00:00',
      'expires_in_seconds': 300,
    };

    final Cart cart = Cart.fromJson(data);

    expect(cart.pendingBooking!.reference, '01K6ABCDEFGHJKMNPQRSTVWXYZ');
    expect(cart.pendingBooking!.expiresInSeconds, 300);
  });
}
