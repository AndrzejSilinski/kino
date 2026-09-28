// Rezerwacja z prawdziwych odpowiedzi serwera (rozpoznanie fazy 3).

import 'package:cinema/models/booking.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

void main() {
  test('czyta opłaconą rezerwację', () {
    final Booking booking = Booking.fromJson(fixtureMap('booking_paid'));

    expect(booking.reference, '01M3MK53H15CAMZB8DE9AFF06Q');
    expect(booking.status, BookingStatus.paid);
    expect(booking.statusLabel, 'Opłacona');
    expect(booking.total.formatted, '43,70 zł');
    expect(booking.paidAt, isNotNull);
    expect(booking.expiresAt, isNull);
    expect(booking.cancellation, isNull);
    expect(booking.status.isPaid, isTrue);
    expect(booking.status.isPending, isFalse);
  });

  test('czyta anulowaną rezerwację razem z informacją o zwrocie', () {
    final Booking booking = Booking.fromJson(fixtureMap('booking_cancelled'));

    expect(booking.status, BookingStatus.cancelled);
    expect(booking.status.isClosed, isTrue);
    expect(booking.cancellation!.refund, 'none');
    expect(booking.cancellation!.isRefundPending, isFalse);
    expect(booking.cancellation!.cancelledAt.time, '18:09');
  });

  test('zwrot w toku jest rozpoznawany', () {
    final Map<String, Object?> data = fixtureMap('booking_cancelled');
    (data['cancellation']! as Map<String, Object?>)['refund'] = 'pending';

    final Booking booking = Booking.fromJson(data);

    expect(booking.cancellation!.isRefundPending, isTrue);
    expect(booking.cancellation!.isRefunded, isFalse);
  });

  test('nieznany status nie wywraca historii zakupów', () {
    // Gdyby kino dodało szósty status, lista rezerwacji ma się pokazać —
    // z etykietą z serwera i bez żadnej akcji, której nie rozumiemy.
    final Map<String, Object?> data = fixtureMap('booking_paid');
    data['status'] = 'w_trakcie_reklamacji';
    data['status_label'] = 'W trakcie reklamacji';

    final Booking booking = Booking.fromJson(data);

    expect(booking.status, BookingStatus.unknown);
    expect(booking.statusLabel, 'W trakcie reklamacji');
    expect(booking.status.isPaid, isFalse);
    expect(booking.status.isPending, isFalse);
    expect(booking.status.isClosed, isFalse);
  });

  test('etykieta statusu pochodzi z serwera, nie z aplikacji', () {
    final Map<String, Object?> data = fixtureMap('booking_paid');
    data['status_label'] = 'Opłacona (gotówką w kasie)';

    expect(Booking.fromJson(data).statusLabel, 'Opłacona (gotówką w kasie)');
  });
}
