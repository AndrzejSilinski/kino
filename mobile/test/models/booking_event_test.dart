// Zdarzenie z kanału rezerwacji — kształt z kodu serwera.

import 'package:cinema/models/booking.dart';
import 'package:cinema/models/booking_event.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, Object?> frame({
  Object? reference = '01M3MK53H15CAMZB8DE9AFF06Q',
  Object? status = 'paid',
  Object? label = 'Opłacona',
}) {
  final Map<String, Object?> data = <String, Object?>{
    'occurred_at': '2026-09-28T18:07:12+00:00',
  };
  if (reference != null) {
    data['reference'] = reference;
  }
  if (status != null) {
    data['status'] = status;
  }
  if (label != null) {
    data['status_label'] = label;
  }
  return data;
}

void main() {
  test('czyta zapłacono', () {
    final BookingStatusEvent event = BookingStatusEvent.tryParse(frame())!;

    expect(event.reference, '01M3MK53H15CAMZB8DE9AFF06Q');
    expect(event.status, BookingStatus.paid);
    expect(event.statusLabel, 'Opłacona');
    expect(event.endsWaiting, isTrue);
  });

  test('anulowanie i zwrot też kończą czekanie', () {
    expect(
      BookingStatusEvent.tryParse(frame(status: 'cancelled'))!.endsWaiting,
      isTrue,
    );
    expect(
      BookingStatusEvent.tryParse(frame(status: 'refunded'))!.endsWaiting,
      isTrue,
    );
  });

  test('pending nie kończy czekania', () {
    // Serwer takiego zdarzenia na kanał rezerwacji nie wysyła, ale gdyby
    // wysłał, nie ma ono prawa przerwać oczekiwania na zapłatę.
    final BookingStatusEvent event = BookingStatusEvent.tryParse(
      frame(status: 'pending', label: 'Oczekuje na płatność'),
    )!;

    expect(event.endsWaiting, isFalse);
  });

  test('nieznany status kończy czekanie i idzie po stan do REST-a', () {
    final BookingStatusEvent event = BookingStatusEvent.tryParse(
      frame(status: 'w_trakcie_reklamacji', label: 'W trakcie reklamacji'),
    )!;

    expect(event.status, BookingStatus.unknown);
    expect(event.endsWaiting, isTrue);
  });

  test('dziwna ramka daje null, a nie wyjątek', () {
    // Ekran, na którym klient właśnie płaci, nie ma prawa zgasnąć z powodu
    // jednej niezrozumiałej ramki (decyzja 305).
    expect(BookingStatusEvent.tryParse(frame(reference: null)), isNull);
    expect(BookingStatusEvent.tryParse(frame(status: null)), isNull);
    expect(BookingStatusEvent.tryParse(frame(reference: '')), isNull);
    expect(BookingStatusEvent.tryParse(frame(status: 7)), isNull);
    expect(BookingStatusEvent.tryParse(<String, Object?>{}), isNull);
  });

  test('brak etykiety nie przeszkadza', () {
    final BookingStatusEvent event = BookingStatusEvent.tryParse(
      frame(label: null),
    )!;

    expect(event.statusLabel, isEmpty);
    expect(event.status, BookingStatus.paid);
  });
}
