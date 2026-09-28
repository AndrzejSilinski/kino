// Rezerwacja: to, co powstaje z koszyka w chwili przejścia do płatności.
//
// Kluczem jest `reference` (ULID), nie sekwencyjne id — tak samo w adresach
// tras. Powód jest po stronie serwera i warto go znać: po numerach po kolei
// dałoby się skanować cudze rezerwacje, a samo 403 potwierdzałoby, że coś
// pod danym numerem istnieje.

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/core/money.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/models/ticket.dart';

/// Status rezerwacji z serwera.
///
/// Nieznana wartość to `unknown`, a nie wyjątek (jak przy statusie miejsca,
/// decyzja 284). Powód jest tu jeszcze mocniejszy: to jest historia ZAKUPÓW
/// klienta. Gdyby kino dodało szósty status, lista rezerwacji miała się nie
/// wywrócić — pokazujemy wtedy etykietę z serwera (`status_label`) i nie
/// pozwalamy na żadną akcję, której nie rozumiemy (decyzja 309).
enum BookingStatus {
  pending('pending'),
  paid('paid'),
  cancelled('cancelled'),
  expired('expired'),
  refunded('refunded'),
  unknown('');

  const BookingStatus(this.value);

  final String value;

  static BookingStatus fromApi(String raw) => values.firstWhere(
    (BookingStatus status) => status.value == raw,
    orElse: () => BookingStatus.unknown,
  );

  /// Czy czeka na pieniądze — tylko wtedy pokazujemy płatność i licznik.
  bool get isPending => this == BookingStatus.pending;

  /// Czy zakup jest zamknięty i bilety istnieją.
  bool get isPaid => this == BookingStatus.paid;

  /// Czy rezerwacja jest zamknięta bez biletów.
  bool get isClosed =>
      this == BookingStatus.cancelled ||
      this == BookingStatus.expired ||
      this == BookingStatus.refunded;
}

/// Szczegóły anulowania — serwer dokłada je dopiero, gdy do niego doszło.
class BookingCancellation {
  const BookingCancellation({required this.cancelledAt, required this.refund});

  factory BookingCancellation.fromJson(Map<String, Object?> json) {
    const String where = 'booking.cancellation';
    return BookingCancellation(
      cancelledAt: CinemaTime.parse(jsonString(json, 'cancelled_at', where)),
      refund: jsonString(json, 'refund', where),
    );
  }

  final CinemaTime cancelledAt;

  /// `none` — nic do zwrotu, `pending` — rozliczenie w toku, `refunded` — zwrócone.
  final String refund;

  bool get isRefundPending => refund == 'pending';

  bool get isRefunded => refund == 'refunded';
}

class Booking {
  const Booking({
    required this.reference,
    required this.status,
    required this.statusLabel,
    required this.total,
    this.createdAt,
    this.paidAt,
    this.expiresAt,
    this.cancellation,
    this.ticketsCount,
    this.screening,
    this.tickets = const <Ticket>[],
  });

  factory Booking.fromJson(Map<String, Object?> json) {
    const String where = 'booking';
    final Object? cancellation = json['cancellation'];
    final Object? screening = json['screening'];
    final Object? tickets = json['tickets'];
    return Booking(
      reference: jsonString(json, 'reference', where),
      status: BookingStatus.fromApi(jsonString(json, 'status', where)),
      // Etykietę BIERZEMY Z SERWERA i nigdy nie składamy własnej: ta sama
      // nazwa statusu ma stać w mailu, w panelu, w SPA i na telefonie.
      statusLabel: jsonString(json, 'status_label', where),
      total: Money.fromJson(jsonChild(json, 'total', where), '$where.total'),
      createdAt: _time(json, 'created_at', where),
      paidAt: _time(json, 'paid_at', where),
      expiresAt: _time(json, 'expires_at', where),
      cancellation: cancellation == null
          ? null
          : BookingCancellation.fromJson(
              jsonMap(cancellation, '$where.cancellation'),
            ),
      // Trzy pola WARUNKOWE (pułapka DW): serwer dokłada je tylko tam, gdzie
      // trasa je załadowała. Lista rezerwacji ma licznik i seans, ale nie ma
      // biletów; szczegóły mają wszystko; odpowiedź checkoutu — nic z tego.
      // Wszystkie trzy muszą więc być opcjonalne, bo ten sam model czyta
      // odpowiedzi trzech różnych tras.
      ticketsCount: jsonIntOrNull(json, 'tickets_count', where),
      screening: screening == null
          ? null
          : ScreeningDetail.fromJson(jsonMap(screening, '$where.screening')),
      tickets: tickets == null
          ? const <Ticket>[]
          : jsonList(tickets, '$where.tickets')
                .map(
                  (Object? item) =>
                      Ticket.fromJson(jsonMap(item, '$where.tickets[]')),
                )
                .toList(growable: false),
    );
  }

  static CinemaTime? _time(
    Map<String, Object?> json,
    String key,
    String where,
  ) {
    final String? raw = jsonStringOrNull(json, key, where);
    return raw == null ? null : CinemaTime.parse(raw);
  }

  final String reference;
  final BookingStatus status;

  /// Gotowy napis z serwera, np. „Oczekuje na płatność”.
  final String statusLabel;

  final Money total;
  final CinemaTime? createdAt;
  final CinemaTime? paidAt;

  /// Koniec okna płatności — tylko dla rezerwacji oczekującej.
  final CinemaTime? expiresAt;

  final BookingCancellation? cancellation;

  /// Liczba biletów. Null na trasach, które jej nie liczą.
  final int? ticketsCount;

  /// Seans z filmem, salą i kinem — lista rezerwacji niesie go w całości, więc
  /// ekran historii nie musi dobierać niczego osobno.
  final ScreeningDetail? screening;

  /// Bilety. Puste na liście rezerwacji: tam wystarczy `ticketsCount`, a każdy
  /// bilet niesie własny adres kodu QR, którego na liście nie pokazujemy.
  final List<Ticket> tickets;

  /// Bilety, które wpuszczają na salę.
  List<Ticket> get validTickets =>
      tickets.where((Ticket ticket) => ticket.hasCode).toList(growable: false);
}
