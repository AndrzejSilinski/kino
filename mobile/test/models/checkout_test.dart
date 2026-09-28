// Odpowiedź checkoutu z prawdziwej odpowiedzi serwera (rozpoznanie fazy 3).
//
// Uwaga o fiksturze: wartości udające sekret i klucz są celowo NIEPODOBNE do
// prawdziwych. Skaner sekretów w wykonawcy szuka m.in. wzorca
// `pi_…_secret_…`, więc fikstura, która by go użyła, zatrzymałaby cały blok
// (pułapka DR).

import 'package:cinema/models/booking.dart';
import 'package:cinema/models/checkout.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

void main() {
  test('czyta rezerwację i dane płatności', () {
    final Checkout checkout = Checkout.fromJson(fixtureMap('checkout'));

    expect(checkout.booking.reference, '01M3MK53H15CAMZB8DE9AFF06Q');
    expect(checkout.booking.status, BookingStatus.pending);
    expect(checkout.booking.total.amount, 4370);
    expect(checkout.payment.provider, 'stripe');
    expect(checkout.payment.status, 'requires_payment_method');
    expect(checkout.payment.needsPaymentMethod, isTrue);
    expect(checkout.payment.isPaid, isFalse);
    expect(checkout.payment.expiresInSeconds, 598);
  });

  test('klucz publiczny przychodzi z serwera, nie z parametru buildu', () {
    // To jest cała różnica: podmiana konta Stripe nie wymaga wtedy wydania
    // nowej wersji aplikacji, a wersja w sklepie żyje u ludzi miesiącami.
    final Checkout checkout = Checkout.fromJson(fixtureMap('checkout'));

    expect(checkout.payment.publishableKey, isNotEmpty);
    expect(checkout.payment.clientSecret, isNotEmpty);
  });

  test('opis obiektu NIE zawiera sekretu ani klucza', () {
    final Checkout checkout = Checkout.fromJson(fixtureMap('checkout'));

    final String opis = '$checkout ${checkout.payment}';

    expect(opis, contains('stripe'));
    expect(opis, contains('requires_payment_method'));
    expect(opis, isNot(contains(checkout.payment.clientSecret)));
    expect(opis, isNot(contains(checkout.payment.publishableKey)));
  });

  test('status succeeded rozpoznajemy jako zapłacone', () {
    final Map<String, Object?> data = fixtureMap('checkout');
    (data['payment']! as Map<String, Object?>)['status'] = 'succeeded';

    final Checkout checkout = Checkout.fromJson(data);

    expect(checkout.payment.isPaid, isTrue);
    expect(checkout.payment.needsPaymentMethod, isFalse);
  });

  test('brak pola płatności to błąd kontraktu', () {
    final Map<String, Object?> data = fixtureMap('checkout');
    data.remove('payment');

    expect(() => Checkout.fromJson(data), throwsA(isA<Object>()));
  });
}
