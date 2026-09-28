// Strona listy paginowanej — koperta z rozpoznania fazy 4.

import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

Paginated<Booking> pageOf(String name) => Paginated<Booking>.fromEnvelope(
  envelope(name),
  'bookings',
  Booking.fromJson,
);

void main() {
  test('czyta pozycje i licznik stron z meta', () {
    final Paginated<Booking> page = pageOf('bookings_page');

    expect(page.items, hasLength(2));
    expect(page.currentPage, 1);
    expect(page.lastPage, 2);
    expect(page.perPage, 2);
    // Wszystkich rezerwacji jest więcej niż na tej stronie — to `total`.
    expect(page.total, 3);
    expect(page.hasMore, isTrue);
    expect(page.isEmpty, isFalse);
  });

  test('ostatnia strona nie obiecuje następnej', () {
    final Paginated<Booking> page = pageOf('bookings_page2');

    expect(page.currentPage, 2);
    expect(page.lastPage, 2);
    expect(page.hasMore, isFalse);
  });

  test('doczytana strona dokłada się na koniec', () {
    final Paginated<Booking> razem = pageOf('bookings_page')
        .followedBy(pageOf('bookings_page2'));

    expect(razem.items, hasLength(3));
    expect(razem.items.map((Booking booking) => booking.status.value), <String>[
      'paid',
      'cancelled',
      'expired',
    ]);
    // Numer strony i licznik biorą się z NOWEJ odpowiedzi.
    expect(razem.currentPage, 2);
    expect(razem.hasMore, isFalse);
  });

  test('brak meta to błąd kontraktu, nie pusta strona', () {
    final Map<String, Object?> bez = envelope('bookings_page');
    bez.remove('meta');

    expect(
      () => Paginated<Booking>.fromEnvelope(bez, 'bookings', Booking.fromJson),
      throwsA(isA<Object>()),
    );
  });

  test('opis strony da się wpisać do logu', () {
    expect(
      pageOf('bookings_page').toString(),
      'Paginated(strona 1 z 2, pozycji 2 z 3)',
    );
  });
}
