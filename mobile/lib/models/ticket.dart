// Bilet z rezerwacji.
//
// NAJWAŻNIEJSZE, CZEGO TU NIE MA: kodu biletu. API go nie oddaje i to jest
// decyzja serwera, nie przeoczenie — kto ma kod, może wygenerować kod QR, więc
// kod nie opuszcza serwera (komentarz w `TicketResource` mówi o tym wprost).
// Aplikacja dostaje `qr_url`: adres obrazu PNG generowanego po stronie serwera.
//
// Decyzja 324: obraz kodu QR pobieramy Z NAGŁÓWKIEM Authorization, a nie
// wstawiamy adresu do zwykłego widgetu obrazka. Rozpoznanie fazy 4 potwierdziło
// oba brzegi tej sprawy: z tokenem adres oddaje 9 kB PNG-a, a bez tokenu 401
// `UNAUTHENTICATED`. Skoro adres sam nie jest przepustką, nie ma powodu, żeby
// wędrował po logach — a adres podpisany w `<img src>` trafiłby do logów nginx
// razem z podpisem. Serwer dokłada do obrazu `Cache-Control: private, no-store`,
// bo bilet to przepustka na salę.
//
// `qr_url` i `seat` są w odpowiedzi WARUNKOWE (serwer dokłada je tylko wtedy,
// gdy załadował odpowiednią relację), więc w modelu są opcjonalne — patrz
// pułapka DW.

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/core/money.dart';

/// Status biletu z serwera.
///
/// Nieznana wartość to `unknown`, a nie wyjątek — z tego samego powodu co przy
/// statusie rezerwacji (decyzja 309): to jest historia zakupów klienta i nie ma
/// prawa się wywrócić, gdy kino doda szósty stan.
enum TicketStatus {
  valid('valid'),
  used('used'),
  cancelled('cancelled'),
  unknown('');

  const TicketStatus(this.value);

  final String value;

  static TicketStatus fromApi(String raw) => values.firstWhere(
    (TicketStatus status) => status.value == raw,
    orElse: () => TicketStatus.unknown,
  );

  /// Czy bilet wpuszcza na salę — tylko wtedy pokazujemy kod QR.
  bool get isValid => this == TicketStatus.valid;

  /// Czy został już zeskanowany przy wejściu.
  bool get isUsed => this == TicketStatus.used;
}

class TicketSeat {
  const TicketSeat({
    required this.id,
    required this.row,
    required this.number,
    required this.label,
    required this.type,
  });

  factory TicketSeat.fromJson(Map<String, Object?> json) {
    const String where = 'ticket.seat';
    return TicketSeat(
      id: jsonInt(json, 'id', where),
      row: jsonString(json, 'row', where),
      number: jsonInt(json, 'number', where),
      label: jsonString(json, 'label', where),
      type: jsonString(json, 'type', where),
    );
  }

  final int id;
  final String row;
  final int number;

  /// Gotowa etykieta z serwera, np. `A3`.
  final String label;

  /// `standard`, `double`, `wheelchair` — w rozpoznaniu wystąpił `double`.
  final String type;
}

class Ticket {
  const Ticket({
    required this.id,
    required this.price,
    required this.status,
    required this.statusLabel,
    this.validatedAt,
    this.qrUrl,
    this.seat,
  });

  factory Ticket.fromJson(Map<String, Object?> json) {
    const String where = 'ticket';
    final Object? seat = json['seat'];
    return Ticket(
      id: jsonInt(json, 'id', where),
      price: Money.fromJson(jsonChild(json, 'price', where), '$where.price'),
      status: TicketStatus.fromApi(jsonString(json, 'status', where)),
      statusLabel: jsonString(json, 'status_label', where),
      validatedAt: _time(json, 'validated_at', where),
      qrUrl: jsonStringOrNull(json, 'qr_url', where),
      seat: seat == null
          ? null
          : TicketSeat.fromJson(jsonMap(seat, '$where.seat')),
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

  final int id;
  final Money price;
  final TicketStatus status;

  /// Etykieta z serwera: „Ważny”, „Wykorzystany”, „Anulowany”.
  final String statusLabel;

  /// Moment zeskanowania przy wejściu na salę.
  final CinemaTime? validatedAt;

  /// Adres obrazu kodu QR. Null, gdy serwer go nie dołączył — wtedy po prostu
  /// nie ma czego pokazać i ekran mówi to wprost, zamiast rysować pustą ramkę.
  final String? qrUrl;

  final TicketSeat? seat;

  /// Czy jest co pokazać przy wejściu na salę.
  bool get hasCode => status.isValid && (qrUrl?.isNotEmpty ?? false);

  @override
  String toString() => 'Ticket($id, ${status.value})';
}
