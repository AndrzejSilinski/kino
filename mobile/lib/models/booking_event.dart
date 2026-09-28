// Zdarzenie zmiany statusu rezerwacji z kanału `private-bookings.{reference}`.
//
// Kształt potwierdzony w kodzie serwera (`App\Events\BookingStatusChanged`):
//
//     {"reference": "01M3...", "status": "paid", "status_label": "Opłacona",
//      "occurred_at": "2026-09-28T20:07:12+02:00"}
//
// Decyzja 317: to zdarzenie jest WYZWALACZEM, a nie danymi. Inaczej niż
// `seats.changed`, które niesie stan absolutny miejsc i wolno go nałożyć na
// plan, tu po otrzymaniu ramki pytamy serwer o rezerwację przez REST. Powody
// są dwa. Pierwszy: rezerwacja to zakup, więc to, co pokażemy klientowi, ma
// pochodzić z jednego źródła — z API, tak samo jak po odpytywaniu i po wejściu
// w historię. Drugi: zdarzenie nie musi dojść. Serwer wysyła je przez Reverba
// z bezpiecznikiem, który przy awarii po prostu nie wysyła (sprawdzone
// w `RealtimeNotifier`), a zaległych ramek nikt nie powtarza. Kanał skraca
// czekanie z sekund do chwili, ale odpytywanie zostaje jako droga pewna.
//
// `occurred_at` świadomie pomijamy: nie porównujemy czasów zdarzeń, bo stan
// bierzemy potem z REST-a i on rozstrzyga kolejność.

import 'package:cinema/models/booking.dart';

class BookingStatusEvent {
  const BookingStatusEvent({
    required this.reference,
    required this.status,
    required this.statusLabel,
  });

  /// Zwraca null przy KAŻDYM nieoczekiwanym kształcie ramki.
  ///
  /// Tak samo jak przy zdarzeniach planu sali (decyzja 305): jedna dziwna ramka
  /// nie ma prawa zgasić ekranu, na którym klient właśnie płaci.
  static BookingStatusEvent? tryParse(Map<String, Object?> data) {
    final Object? reference = data['reference'];
    final Object? status = data['status'];
    if (reference is! String || reference.isEmpty || status is! String) {
      return null;
    }
    final Object? label = data['status_label'];
    return BookingStatusEvent(
      reference: reference,
      status: BookingStatus.fromApi(status),
      statusLabel: label is String ? label : '',
    );
  }

  final String reference;
  final BookingStatus status;

  /// Etykieta z serwera. Pusta, gdy ramka jej nie miała — wtedy ekran pokaże
  /// etykietę z rezerwacji pobranej przez REST.
  final String statusLabel;

  /// Czy po tym zdarzeniu nie ma już na co czekać.
  ///
  /// Nieznany status też kończy czekanie: skoro rezerwacja nie jest już
  /// `pending`, coś się rozstrzygnęło, a co dokładnie — powie REST.
  bool get endsWaiting => !status.isPending;

  @override
  String toString() => 'BookingStatusEvent($reference, ${status.value})';
}
