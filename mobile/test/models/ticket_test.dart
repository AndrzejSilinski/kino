// Bilet z prawdziwej odpowiedzi serwera (rozpoznanie fazy 4).

import 'package:cinema/models/booking.dart';
import 'package:cinema/models/ticket.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

List<Map<String, Object?>> ticketsJson() =>
    (fixtureMap('booking_tickets')['tickets']! as List<Object?>)
        .cast<Map<String, Object?>>();

void main() {
  test('czyta ważny bilet z miejscem i adresem kodu', () {
    final Ticket ticket = Ticket.fromJson(ticketsJson().first);

    expect(ticket.id, 9);
    expect(ticket.status, TicketStatus.valid);
    expect(ticket.statusLabel, 'Ważny');
    expect(ticket.price.formatted, '25,30 zł');
    expect(ticket.seat!.label, 'A3');
    expect(ticket.seat!.type, 'double');
    expect(ticket.validatedAt, isNull);
    expect(ticket.qrUrl, endsWith('/tickets/9/qr'));
    expect(ticket.hasCode, isTrue);
  });

  test('bilet wykorzystany zna moment wejścia i NIE pokazuje kodu', () {
    final Ticket ticket = Ticket.fromJson(ticketsJson()[1]);

    expect(ticket.status, TicketStatus.used);
    expect(ticket.status.isUsed, isTrue);
    expect(ticket.validatedAt!.time, '19:31');
    // Adres kodu przychodzi dalej, ale nie ma po co go pokazywać: bilet już
    // wpuścił na salę. Gdyby ekran pokazał kod, klient mógłby próbować wejść
    // drugi raz i dostać odmowę przy bramce — bez żadnej winy.
    expect(ticket.hasCode, isFalse);
  });

  test('brak adresu kodu nie wywraca biletu', () {
    // Serwer dokłada qr_url warunkowo (pułapka DW).
    final Map<String, Object?> json = Map<String, Object?>.from(
      ticketsJson().first,
    );
    json['qr_url'] = null;

    final Ticket ticket = Ticket.fromJson(json);

    expect(ticket.qrUrl, isNull);
    expect(ticket.hasCode, isFalse);
    expect(ticket.status, TicketStatus.valid);
  });

  test('brak miejsca nie wywraca biletu', () {
    final Map<String, Object?> json = Map<String, Object?>.from(
      ticketsJson().first,
    );
    json.remove('seat');

    expect(Ticket.fromJson(json).seat, isNull);
  });

  test('nieznany status biletu nie wywraca historii', () {
    final Map<String, Object?> json = Map<String, Object?>.from(
      ticketsJson().first,
    );
    json['status'] = 'zwrocony_do_kasy';
    json['status_label'] = 'Zwrócony w kasie';

    final Ticket ticket = Ticket.fromJson(json);

    expect(ticket.status, TicketStatus.unknown);
    expect(ticket.statusLabel, 'Zwrócony w kasie');
    expect(ticket.hasCode, isFalse);
  });

  test('rezerwacja ze szczegółów niesie bilety, seans i licznik', () {
    final Booking booking = Booking.fromJson(fixtureMap('booking_tickets'));

    expect(booking.ticketsCount, 2);
    expect(booking.tickets, hasLength(2));
    // Tylko jeden bilet ma co pokazać przy wejściu.
    expect(booking.validTickets, hasLength(1));
    expect(booking.screening!.movie.brief.title, 'Parasite');
    expect(booking.screening!.hall.cinema.name, 'Kino Bałtyk');
    // Seans w rezerwacji NIE niesie cennika i to jest poprawna odpowiedź.
    expect(booking.screening!.prices, isEmpty);
    expect(booking.screening!.cheapest, isNull);
  });

  test('rezerwacja z listy nie ma biletów, ale ma licznik i seans', () {
    final List<Object?> lista =
        envelope('bookings_page')['data']! as List<Object?>;
    final Booking booking = Booking.fromJson(
      lista.first! as Map<String, Object?>,
    );

    expect(booking.tickets, isEmpty);
    expect(booking.ticketsCount, 2);
    expect(booking.screening, isNotNull);
  });

  test('rezerwacja z checkoutu nie ma ani biletów, ani seansu', () {
    // Ten sam model czyta trzy różne trasy (pułapka DW).
    final Booking booking = Booking.fromJson(
      fixtureMap('checkout')['booking']! as Map<String, Object?>,
    );

    expect(booking.tickets, isEmpty);
    expect(booking.ticketsCount, isNull);
    expect(booking.screening, isNull);
  });
}
