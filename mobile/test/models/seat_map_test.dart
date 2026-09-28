// Plan sali powstał z prawdziwej odpowiedzi serwera (rozpoznanie fazy 2):
// test/fixtures/seat_map.json. Najważniejsze są tu dwie rzeczy, których nie
// widać w kształcie pól: nieznany status nie może wywalić ekranu, a nałożenie
// zdarzenia WebSocketa nie może odebrać mi moich miejsc.

import 'package:cinema/models/seat_map.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

SeatMap map() => SeatMap.fromJson(fixtureMap('seat_map'));

void main() {
  test('czyta seans, wymiary sali i wszystkie miejsca', () {
    final SeatMap plan = map();

    expect(plan.screening.id, 380);
    expect(plan.screening.hall.name, 'Sala B');
    expect(plan.rows, 2);
    expect(plan.columns, 4);
    expect(plan.version, 7);
    expect(plan.seats.length, 8);
    expect(plan.summary.total, 8);
    expect(plan.summary.free, 4);
    expect(plan.summary.heldByYou, 1);
  });

  test('kolejność z serwera zostaje zachowana', () {
    expect(map().ordered.map((Seat seat) => seat.label).toList(), <String>[
      'A1',
      'A2',
      'A3',
      'A4',
      'B1',
      'B2',
      'B3',
      'B4',
    ]);
  });

  test('czyta współrzędne, kategorię, cenę i status miejsca', () {
    final Seat seat = map().seats[102]!;

    expect(seat.label, 'A2');
    expect(seat.x, 1);
    expect(seat.y, 0);
    expect(seat.category.name, 'Standardowe');
    expect(seat.category.color, '#4B5563');
    expect(seat.price?.formatted, '25,30 zł');
    expect(seat.status, SeatStatus.free);
    expect(seat.isSellable, isTrue);
  });

  test('czas wygaśnięcia jest tylko przy MOJEJ blokadzie', () {
    final SeatMap plan = map();

    expect(plan.seats[103]!.status, SeatStatus.heldByYou);
    expect(plan.seats[103]!.lockExpiresInSeconds, 540);
    expect(plan.seats[104]!.status, SeatStatus.held);
    expect(plan.seats[104]!.lockExpiresInSeconds, isNull);
  });

  test('wolne miejsce bez ceny nie jest do kupienia', () {
    final Seat seat = map().seats[107]!;

    expect(seat.status, SeatStatus.free);
    expect(seat.price, isNull);
    expect(seat.category.id, isNull);
    expect(seat.isSellable, isFalse);
  });

  test('nieznany status to unavailable, a nie wyjątek', () {
    expect(SeatStatus.fromApi('reserved_for_staff'), SeatStatus.unavailable);
    expect(SeatStatus.fromApi('held_by_you'), SeatStatus.heldByYou);

    final Map<String, Object?> data = fixtureMap('seat_map');
    final List<Object?> seats = data['seats']! as List<Object?>;
    (seats.first! as Map<String, Object?>)['status'] = 'nowy_status';

    final SeatMap plan = SeatMap.fromJson(data);

    expect(plan.seats[101]!.status, SeatStatus.unavailable);
    expect(plan.seats[101]!.isSellable, isFalse);
  });

  group('zdarzenie seats.changed', () {
    test('starsza albo równa wersja jest pomijana', () {
      final SeatMap plan = map();

      final SeatMap unchanged = plan.applyChange(
        version: 7,
        changes: <String, List<int>>{
          'sold': <int>[101],
        },
      );

      expect(unchanged, same(plan));
      expect(unchanged.seats[101]!.status, SeatStatus.free);
    });

    test('nowsza wersja nakłada stan absolutny i przelicza podsumowanie', () {
      final SeatMap plan = map();

      final SeatMap next = plan.applyChange(
        version: 9,
        changes: <String, List<int>>{
          'sold': <int>[101],
          'free': <int>[104],
        },
      );

      expect(next.version, 9);
      expect(next.seats[101]!.status, SeatStatus.sold);
      expect(next.seats[104]!.status, SeatStatus.free);
      expect(next.summary.sold, 2);
      expect(next.summary.free, 4);
      expect(next.summary.held, 0);
      expect(next.summary.total, 8);
    });

    test('MOJE miejsca zostają moje, choć broadcast mówi o nich "held"', () {
      // Broadcast nie wie, kto słucha (inaczej zdradzałby cudzy koszyk), więc
      // o moim miejscu też mówi "held". Bez tej reguły po cudzym kliknięciu
      // moje fotele zmieniłyby kolor na "zajęte przez kogoś innego".
      final SeatMap next = map().applyChange(
        version: 8,
        changes: <String, List<int>>{
          'held': <int>[103, 104],
        },
        mine: <int>{103},
      );

      expect(next.seats[103]!.status, SeatStatus.heldByYou);
      expect(next.seats[103]!.lockExpiresInSeconds, 540);
      expect(next.seats[104]!.status, SeatStatus.held);
      expect(next.summary.heldByYou, 1);
    });

    test('identyfikator z innej sali jest ignorowany', () {
      final SeatMap next = map().applyChange(
        version: 8,
        changes: <String, List<int>>{
          'sold': <int>[999999],
        },
      );

      expect(next.seats.length, 8);
      expect(next.version, 8);
    });
  });

  test('miejsca odrzucone przez 409 malują się jako zajęte', () {
    final SeatMap plan = map();

    final SeatMap next = plan.markTaken(<int>[101, 102]);

    expect(next.seats[101]!.status, SeatStatus.held);
    expect(next.seats[102]!.status, SeatStatus.held);
    expect(next.summary.held, 3);
    expect(next.summary.free, 2);
    // Wersji nie ruszamy: to wiedza z odpowiedzi HTTP, nie ze strumienia.
    expect(next.version, plan.version);
  });

  test('markTaken bez zmian zwraca ten sam obiekt', () {
    final SeatMap plan = map();

    expect(plan.markTaken(<int>[104]), same(plan));
  });
}
