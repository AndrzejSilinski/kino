// Zdarzenia planu sali z kanału `private-screenings.{id}`.
//
// Payload potwierdzony rozpoznaniem fazy 2:
//   seats.changed -> {screening_id, version, seats: {status: [id, …]}}
//   seats.resync  -> {screening_id, version}
//
// Parsowanie jest TOLERANCYJNE, w przeciwieństwie do modeli REST-owych, które
// przy niezgodnym kształcie rzucają INVALID_RESPONSE (decyzja 305). Różnica
// jest celowa: odpowiedź REST to odpowiedź na pytanie użytkownika i lepiej
// pokazać błąd niż zgadywać, a zdarzenie ze strumienia przychodzi samo. Gdyby
// jedno niezrozumiałe zdarzenie wywracało ekran wyboru miejsc, klient straciłby
// koszyk z powodu, na który nie ma wpływu. Zdarzenie, którego nie rozumiemy,
// pomijamy — a stan i tak dociągnie pełne pobranie po REST.

import 'package:cinema/core/realtime.dart';

class SeatsEvent {
  const SeatsEvent({
    required this.screeningId,
    required this.version,
    required this.changes,
    required this.isResync,
  });

  /// `null`, gdy zdarzenie nie jest zrozumiałe albo nie dotyczy planu sali.
  static SeatsEvent? tryParse(RealtimeEvent event) {
    final bool resync = event.event == 'seats.resync';
    if (event.event != 'seats.changed' && !resync) {
      return null;
    }
    final Object? screeningId = event.data['screening_id'];
    final Object? version = event.data['version'];
    if (screeningId is! int || version is! int) {
      return null;
    }
    return SeatsEvent(
      screeningId: screeningId,
      version: version,
      changes: resync
          ? const <String, List<int>>{}
          : _changes(event.data['seats']),
      isResync: resync,
    );
  }

  /// `{status: [id, …]}` — stan ABSOLUTNY wymienionych miejsc.
  static Map<String, List<int>> _changes(Object? raw) {
    if (raw is! Map<String, Object?>) {
      return const <String, List<int>>{};
    }
    final Map<String, List<int>> out = <String, List<int>>{};
    raw.forEach((String status, Object? ids) {
      if (ids is List) {
        out[status] = ids.whereType<int>().toList(growable: false);
      }
    });
    return out;
  }

  final int screeningId;

  /// Wersja stanu miejsc. Zdarzenie z wersją nie większą niż mamy pomijamy.
  final int version;

  final Map<String, List<int>> changes;

  /// `seats.resync`: serwer mówi „pobierz pełny stan”, nie podaje zmian.
  final bool isResync;
}
