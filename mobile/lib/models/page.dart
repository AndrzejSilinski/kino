// Strona listy paginowanej — koperta `data` + `links` + `meta`.
//
// Decyzja 323: STRONICUJEMY PO NUMERZE STRONY, a nie po adresach z `links`.
// Wygląda to na krok w tył, bo serwer podaje gotowe `links.next` — ale te
// adresy są zbudowane z hosta, którym serwer widzi siebie. Rozpoznanie fazy 4
// pokazało w nich dosłownie `http://localhost:8080/api/v1/bookings?page=3`,
// a na telefonie `localhost` to TELEFON. Pójście za takim adresem nie tylko
// by nie zadziałało — trafiłoby w cokolwiek, co na urządzeniu nasłuchuje na
// tym porcie. Numer strony jest niezależny od hosta, a adres bazowy aplikacja
// i tak zna z parametru buildu.
//
// `meta.links` (lista guzików „1 2 3 … następna”) świadomie pomijamy: to
// element interfejsu wymyślony pod stronę w przeglądarce. Na telefonie lista
// doczytuje się przy przewijaniu, więc potrzebne jest jedno pytanie:
// „czy jest jeszcze strona?”.

import 'package:cinema/core/json.dart';

class Paginated<T> {
  const Paginated({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  factory Paginated.fromEnvelope(
    Map<String, Object?> envelope,
    String where,
    T Function(Map<String, Object?> item) parse,
  ) {
    final Map<String, Object?> meta = jsonChild(envelope, 'meta', where);
    return Paginated<T>(
      items: jsonList(envelope['data'], '$where.data')
          .map((Object? item) => parse(jsonMap(item, '$where.data[]')))
          .toList(growable: false),
      currentPage: jsonInt(meta, 'current_page', '$where.meta'),
      lastPage: jsonInt(meta, 'last_page', '$where.meta'),
      perPage: jsonInt(meta, 'per_page', '$where.meta'),
      total: jsonInt(meta, 'total', '$where.meta'),
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int perPage;

  /// Wszystkich pozycji, nie tylko na tej stronie — pokazujemy to w nagłówku.
  final int total;

  bool get hasMore => currentPage < lastPage;

  bool get isEmpty => items.isEmpty;

  /// Dokłada kolejną stronę na koniec.
  ///
  /// Licznik i numer strony biorę Z NOWEJ odpowiedzi, bo między pytaniami mogła
  /// dojść rezerwacja — wtedy `total` się zmienia i to nowa wartość jest prawdą.
  Paginated<T> followedBy(Paginated<T> next) => Paginated<T>(
    items: <T>[...items, ...next.items],
    currentPage: next.currentPage,
    lastPage: next.lastPage,
    perPage: next.perPage,
    total: next.total,
  );

  @override
  String toString() =>
      'Paginated(strona $currentPage z $lastPage, pozycji ${items.length} z $total)';
}
