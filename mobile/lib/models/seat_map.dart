// Plan sali: stan każdego fotela na jednym seansie.
//
// Kształt potwierdzony rozpoznaniem fazy 2 na żywym serwerze: `data.screening`
// to dokładnie ta sama koperta co `GET /screenings/{id}` (razem z wymiarami
// siatki sali), `data.seats` to lista posortowana po `position.y`, potem `x`,
// a `data.seat_state_version` to DOLNA granica wersji stanu — zdarzenia
// WebSocketa z wersją nowszą nakładamy na to, co przyszło przez REST.
//
// Miejsca trzymamy w mapie `id -> miejsce`, a nie w liście, bo:
//   - zdarzenie `seats.changed` niesie listy identyfikatorów per status
//     i nakładanie go na listę wymagałoby szukania po kolei,
//   - plan sali rysuje się po współrzędnych, a nie po indeksie w liście.
// Mapa Darta zachowuje kolejność wstawiania, więc `seats.values` to nadal
// kolejność z serwera — jedna struktura obsługuje oba zastosowania.

import 'package:cinema/core/json.dart';
import 'package:cinema/core/money.dart';
import 'package:cinema/models/screening.dart';

/// Status miejsca w planie sali.
///
/// Nieznana wartość z serwera to `unavailable`, a nie wyjątek (decyzja 284):
/// gdyby kino dodało kiedyś czwarty status, aplikacja ma pokazać plan sali
/// z jednym fotelem nie do kupienia, a nie pusty ekran błędu. Bezpieczny
/// domyślny wybór to zawsze „nie wolno sprzedać”.
enum SeatStatus {
  free('free'),
  held('held'),
  heldByYou('held_by_you'),
  sold('sold'),
  unavailable('unavailable');

  const SeatStatus(this.value);

  /// Wartość z API.
  final String value;

  static SeatStatus fromApi(String raw) => values.firstWhere(
    (SeatStatus status) => status.value == raw,
    orElse: () => SeatStatus.unavailable,
  );

  /// Czy kliknięcie ma dodać miejsce do koszyka.
  bool get isFree => this == SeatStatus.free;

  /// Czy to moje miejsce (kliknięcie je zwolni).
  bool get isMine => this == SeatStatus.heldByYou;

  /// Czy w ogóle da się w nie kliknąć.
  bool get isTappable => isFree || isMine;
}

/// Kategoria cenowa miejsca. Wszystkie pola mogą być puste, bo serwer czyta je
/// z relacji (`priceCategory?->id`) — miejsce bez kategorii jest legalne.
class SeatCategory {
  const SeatCategory({this.id, this.name, this.color});

  factory SeatCategory.fromJson(Map<String, Object?> json, String where) =>
      SeatCategory(
        id: jsonIntOrNull(json, 'id', where),
        name: jsonStringOrNull(json, 'name', where),
        color: jsonStringOrNull(json, 'color', where),
      );

  final int? id;
  final String? name;

  /// Kolor z panelu administracyjnego, np. `#4B5563`.
  final String? color;
}

class Seat {
  const Seat({
    required this.id,
    required this.row,
    required this.number,
    required this.label,
    required this.type,
    required this.typeLabel,
    required this.x,
    required this.y,
    required this.category,
    required this.status,
    this.price,
    this.lockExpiresInSeconds,
  });

  factory Seat.fromJson(Map<String, Object?> json) {
    const String where = 'seats[]';
    final Map<String, Object?> position = jsonChild(json, 'position', where);
    final Object? price = json['price'];
    return Seat(
      id: jsonInt(json, 'id', where),
      row: jsonString(json, 'row', where),
      number: jsonInt(json, 'number', where),
      label: jsonString(json, 'label', where),
      type: jsonString(json, 'type', where),
      typeLabel: jsonString(json, 'type_label', where),
      x: jsonInt(position, 'x', '$where.position'),
      y: jsonInt(position, 'y', '$where.position'),
      category: SeatCategory.fromJson(
        jsonChild(json, 'category', where),
        '$where.category',
      ),
      // Miejsce bez ceny w cenniku seansu jest możliwe; serwer odmówi jego
      // zablokowania, więc aplikacja pokazuje je jako niedostępne.
      price: price == null
          ? null
          : Money.fromJson(jsonMap(price, '$where.price'), '$where.price'),
      status: SeatStatus.fromApi(jsonString(json, 'status', where)),
      // Czas wygaśnięcia przychodzi TYLKO przy własnej blokadzie — przy cudzej
      // byłby wyciekiem informacji o cudzym koszyku.
      lockExpiresInSeconds: jsonIntOrNull(
        json,
        'lock_expires_in_seconds',
        where,
      ),
    );
  }

  final int id;
  final String row;
  final int number;
  final String label;
  final String type;
  final String typeLabel;

  /// Współrzędne w siatce sali (kolumna, rząd) — liczone od zera.
  final int x;
  final int y;

  final SeatCategory category;
  final SeatStatus status;
  final Money? price;
  final int? lockExpiresInSeconds;

  /// Czy miejsce da się kupić: musi być wolne i mieć cenę.
  bool get isSellable => status.isFree && price != null;

  Seat withStatus(SeatStatus next) => Seat(
    id: id,
    row: row,
    number: number,
    label: label,
    type: type,
    typeLabel: typeLabel,
    x: x,
    y: y,
    category: category,
    status: next,
    price: price,
    lockExpiresInSeconds: next.isMine ? lockExpiresInSeconds : null,
  );
}

/// Licznik miejsc per status — serwer przysyła go gotowego.
class SeatSummary {
  const SeatSummary({
    required this.free,
    required this.held,
    required this.heldByYou,
    required this.sold,
    required this.unavailable,
    required this.total,
  });

  factory SeatSummary.fromJson(Map<String, Object?> json) {
    const String where = 'summary';
    return SeatSummary(
      free: jsonInt(json, 'free', where),
      held: jsonInt(json, 'held', where),
      heldByYou: jsonInt(json, 'held_by_you', where),
      sold: jsonInt(json, 'sold', where),
      unavailable: jsonInt(json, 'unavailable', where),
      total: jsonInt(json, 'total', where),
    );
  }

  /// Przeliczenie po nałożeniu zdarzenia WebSocketa — wtedy serwer nie
  /// przysyła podsumowania, a licznik „wolnych” jest na ekranie.
  factory SeatSummary.count(Iterable<Seat> seats) {
    final Map<SeatStatus, int> counts = <SeatStatus, int>{
      for (final SeatStatus status in SeatStatus.values) status: 0,
    };
    int total = 0;
    for (final Seat seat in seats) {
      counts[seat.status] = (counts[seat.status] ?? 0) + 1;
      total++;
    }
    return SeatSummary(
      free: counts[SeatStatus.free]!,
      held: counts[SeatStatus.held]!,
      heldByYou: counts[SeatStatus.heldByYou]!,
      sold: counts[SeatStatus.sold]!,
      unavailable: counts[SeatStatus.unavailable]!,
      total: total,
    );
  }

  final int free;
  final int held;
  final int heldByYou;
  final int sold;
  final int unavailable;
  final int total;
}

class SeatMap {
  const SeatMap({
    required this.screening,
    required this.seats,
    required this.summary,
    required this.version,
  });

  factory SeatMap.fromJson(Map<String, Object?> data) {
    const String where = 'seat-map';
    final List<Object?> raw = jsonList(data['seats'], '$where.seats');
    final Map<int, Seat> seats = <int, Seat>{};
    for (final Object? item in raw) {
      final Seat seat = Seat.fromJson(jsonMap(item, '$where.seats[]'));
      seats[seat.id] = seat;
    }
    return SeatMap(
      screening: ScreeningDetail.fromJson(jsonChild(data, 'screening', where)),
      seats: Map<int, Seat>.unmodifiable(seats),
      summary: SeatSummary.fromJson(jsonChild(data, 'summary', where)),
      version: jsonInt(data, 'seat_state_version', where),
    );
  }

  final ScreeningDetail screening;

  /// `id miejsca -> miejsce`, w kolejności przysłanej przez serwer (rząd, potem
  /// numer w rzędzie).
  final Map<int, Seat> seats;

  final SeatSummary summary;

  /// Wersja stanu miejsc. Zdarzenia z wersją nie większą niż ta są już
  /// uwzględnione w `seats` i muszą być pominięte.
  final int version;

  Iterable<Seat> get ordered => seats.values;

  int get rows => screening.hall.grid.rows;

  int get columns => screening.hall.grid.columns;

  /// Nałożenie zdarzenia `seats.changed` z kanału seansu (blok G).
  ///
  /// Payload potwierdzony rozpoznaniem: `{screening_id, version, seats: {status:
  /// [id, …]}}` — stan ABSOLUTNY wymienionych miejsc, nie różnica. Dwie reguły:
  ///
  ///   1. Starsza albo równa wersja to zdarzenie, które już mamy w stanie
  ///      (REST dał dolną granicę) — pomijamy je, zamiast cofać plan sali.
  ///   2. Broadcast nie wie, kto słucha: MOJE miejsca też opisuje jako `held`,
  ///      bo inaczej zdradzałby cudzy koszyk. Dlatego identyfikatory z koszyka
  ///      wygrywają nad statusem ze zdarzenia (decyzja 285) — bez tego po
  ///      cudzym kliknięciu moje miejsca zmieniłyby kolor na „zajęte”.
  SeatMap applyChange({
    required int version,
    required Map<String, List<int>> changes,
    Set<int> mine = const <int>{},
  }) {
    if (version <= this.version) {
      return this;
    }
    final Map<int, Seat> next = Map<int, Seat>.of(seats);
    changes.forEach((String rawStatus, List<int> ids) {
      final SeatStatus status = SeatStatus.fromApi(rawStatus);
      for (final int id in ids) {
        final Seat? seat = next[id];
        if (seat == null) {
          continue; // miejsce z innej sali albo stare id
        }
        final SeatStatus effective =
            mine.contains(id) && status == SeatStatus.held
            ? SeatStatus.heldByYou
            : status;
        next[id] = seat.withStatus(effective);
      }
    });
    return SeatMap(
      screening: screening,
      seats: Map<int, Seat>.unmodifiable(next),
      summary: SeatSummary.count(next.values),
      version: version,
    );
  }

  /// Przemalowanie miejsc, które serwer właśnie odrzucił jako zajęte (409).
  ///
  /// `context.seat_ids` z odpowiedzi mówi, KTÓRE fotele zajął ktoś inny —
  /// serwer daje to właśnie po to, żeby klient nie musiał pobierać całego
  /// planu sali od nowa (decyzja 286). Wersji nie ruszamy: to nasza wiedza
  /// z odpowiedzi HTTP, a nie zdarzenie ze strumienia.
  SeatMap markTaken(Iterable<int> ids) {
    final Map<int, Seat> next = Map<int, Seat>.of(seats);
    bool changed = false;
    for (final int id in ids) {
      final Seat? seat = next[id];
      if (seat == null || seat.status == SeatStatus.held) {
        continue;
      }
      next[id] = seat.withStatus(SeatStatus.held);
      changed = true;
    }
    if (!changed) {
      return this;
    }
    return SeatMap(
      screening: screening,
      seats: Map<int, Seat>.unmodifiable(next),
      summary: SeatSummary.count(next.values),
      version: version,
    );
  }
}
