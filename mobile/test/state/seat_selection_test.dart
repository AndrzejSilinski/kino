// Wybór miejsc: co dzieje się w stanie po kliknięciu w fotel.
//
// Testy trzymają się reguł potwierdzonych rozpoznaniem fazy 2:
//   - koszyk pochodzi z ODPOWIEDZI serwera, nie z domysłu aplikacji,
//   - 409 przemalowuje odrzucone fotele z `context.seat_ids`, bez pobierania
//     całego planu sali,
//   - SEATS_NOT_IN_HALL oznacza nieaktualny plan sali, więc go odświeżamy,
//   - limit miejsc i miejsce bez ceny zatrzymujemy u siebie, żeby nie zużywać
//     limitu 30 żądań na minutę na pewne odmowy.

import 'dart:convert';

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/state/booking.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

const int screeningId = 380;

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

/// Koszyk zbudowany z miejsc planu sali — żeby nie mnożyć plików fikstur.
/// Kształt jest ten sam co w `test/fixtures/cart.json`.
Map<String, Object?> cartOf(List<int> seatIds) {
  final SeatMap plan = SeatMap.fromJson(fixtureMap('seat_map'));
  final List<Object?> seats = <Object?>[];
  int total = 0;
  for (final int id in seatIds) {
    final Seat seat = plan.seats[id]!;
    total += seat.price!.amount;
    seats.add(<String, Object?>{
      'seat_id': seat.id,
      'row': seat.row,
      'number': seat.number,
      'label': seat.label,
      'type': seat.type,
      'category': <String, Object?>{
        'id': seat.category.id,
        'name': seat.category.name,
        'color': seat.category.color,
      },
      'price': <String, Object?>{
        'amount': seat.price!.amount,
        'currency': seat.price!.currency,
        'formatted': seat.price!.formatted,
      },
      'lock_expires_at': '2026-09-28T15:40:52+00:00',
    });
  }
  return <String, Object?>{
    'data': <String, Object?>{
      'seats': seats,
      'seats_count': seats.length,
      'total': <String, Object?>{
        'amount': total,
        'currency': 'PLN',
        'formatted': '$total gr',
      },
      'expires_at': seats.isEmpty ? null : '2026-09-28T15:40:52+00:00',
      'expires_in_seconds': seats.isEmpty ? null : 540,
      'pending_booking': null,
    },
    'meta': <String, Object?>{'screening_id': screeningId},
  };
}

/// Atrapa serwera: plan sali z fikstury, koszyk i odpowiedzi na operacje
/// podaje test.
class FakeApi {
  FakeApi({this.onLock, this.onDelete, this.maxSeats = 10});

  final http.Response Function(List<int> seatIds)? onLock;
  final http.Response Function(String path)? onDelete;
  final int maxSeats;

  Map<String, Object?> cart = cartOf(<int>[]);
  int seatMapCalls = 0;
  int lockCalls = 0;
  int deleteCalls = 0;

  http.Response handle(http.Request request) {
    final String path = request.url.path;
    if (path.endsWith('/client-config')) {
      final Map<String, Object?> config = envelope('client_config');
      final Map<String, Object?> data = config['data']! as Map<String, Object?>;
      final Map<String, Object?> booking =
          data['booking']! as Map<String, Object?>;
      booking['max_seats_per_session'] = maxSeats;
      return reply(config);
    }
    if (path.endsWith('/seat-map')) {
      seatMapCalls++;
      return reply(envelope('seat_map'));
    }
    if (request.method == 'POST') {
      lockCalls++;
      final Map<String, Object?> body =
          jsonDecode(request.body) as Map<String, Object?>;
      final List<int> ids = (body['seat_ids']! as List<Object?>).cast<int>();
      return onLock?.call(ids) ?? reply(cart, 201);
    }
    if (request.method == 'DELETE') {
      deleteCalls++;
      return onDelete?.call(path) ?? reply(cart);
    }
    return reply(cart);
  }
}

ProviderContainer containerFor(FakeApi api) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      // Bez tego sesja próbowałaby pisać do Keystore, którego w teście
      // jednostkowym nie ma.
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
    ],
  );
  addTearDown(container.dispose);
  return container;
}

Future<SeatSelectionState> load(ProviderContainer container) =>
    container.read(seatSelectionProvider(screeningId).future);

SeatSelection notifier(ProviderContainer container) =>
    container.read(seatSelectionProvider(screeningId).notifier);

SeatSelectionState current(ProviderContainer container) =>
    container.read(seatSelectionProvider(screeningId)).requireValue;

Seat seat(ProviderContainer container, int id) =>
    current(container).map.seats[id]!;

void main() {
  test(
    'wejście na ekran pobiera plan sali, koszyk i limit z konfiguracji',
    () async {
      final FakeApi api = FakeApi();
      final ProviderContainer container = containerFor(api);

      final SeatSelectionState state = await load(container);

      expect(state.map.seats.length, 8);
      expect(state.cart.isEmpty, isTrue);
      expect(state.maxSeats, 10);
      expect(state.remaining, 10);
      expect(api.seatMapCalls, 1);
    },
  );

  test('o moich miejscach rozstrzyga koszyk, o cudzych plan sali', () async {
    final FakeApi api = FakeApi()..cart = cartOf(<int>[103]);
    final ProviderContainer container = containerFor(api);

    final SeatSelectionState state = await load(container);

    expect(state.statusOf(state.map.seats[103]!), SeatStatus.heldByYou);
    expect(state.statusOf(state.map.seats[104]!), SeatStatus.held);
    expect(state.statusOf(state.map.seats[105]!), SeatStatus.sold);
  });

  test('kliknięcie w wolne miejsce blokuje je i bierze koszyk z odpowiedzi', () async {
    final FakeApi api = FakeApi(
      onLock: (List<int> ids) => reply(cartOf(ids), 201),
    );
    final ProviderContainer container = containerFor(api);
    await load(container);

    await notifier(container).toggle(seat(container, 102));

    final SeatSelectionState state = current(container);
    expect(api.lockCalls, 1);
    expect(state.cart.seatsCount, 1);
    expect(state.cart.seatIds, <int>{102});
    expect(state.busy, isEmpty);
    expect(state.notice, isNull);
    // Plan sali z serwera nadal mówi "free" — to koszyk maluje fotel jako mój.
    expect(state.map.seats[102]!.status, SeatStatus.free);
    expect(state.statusOf(state.map.seats[102]!), SeatStatus.heldByYou);
  });

  test(
    'kliknięcie w MOJE miejsce je zwalnia i fotel wraca do wolnych',
    () async {
      final FakeApi api = FakeApi(
        onDelete: (String path) => reply(cartOf(<int>[])),
      )..cart = cartOf(<int>[103]);
      final ProviderContainer container = containerFor(api);
      await load(container);
      expect(current(container).cart.seatsCount, 1);

      await notifier(container).toggle(seat(container, 103));

      final SeatSelectionState state = current(container);
      expect(api.deleteCalls, 1);
      expect(state.cart.isEmpty, isTrue);
      // Plan sali wciąż pamięta moją blokadę; koszyk jest prawdą, więc fotel
      // pokazujemy jako wolny, zamiast czekać na ponowne pobranie planu.
      expect(state.map.seats[103]!.status, SeatStatus.heldByYou);
      expect(state.statusOf(state.map.seats[103]!), SeatStatus.free);
    },
  );

  test('konflikt 409 pokazuje komunikat i maluje fotel jako zajęty', () async {
    final FakeApi api = FakeApi(
      // Serwer odpowiada tak jak na żywo: kod, komunikat z etykietą fotela
      // i identyfikatory w context.seat_ids.
      onLock: (List<int> ids) => reply(<String, Object?>{
        'message': 'Miejsca A1 zostały właśnie zajęte przez kogoś innego.',
        'code': 'SEATS_UNAVAILABLE',
        'context': <String, Object?>{
          'seat_ids': ids,
          'seats': <String>['A1'],
        },
      }, 409),
    );
    final ProviderContainer container = containerFor(api);
    await load(container);
    expect(seat(container, 101).status, SeatStatus.free);

    await notifier(container).toggle(seat(container, 101));

    final SeatSelectionState state = current(container);
    expect(state.notice!.code, 'SEATS_UNAVAILABLE');
    expect(state.notice!.message, contains('A1'));
    expect(state.cart.isEmpty, isTrue);
    expect(state.busy, isEmpty);
    // Fotel z context.seat_ids jest już zajęty, a planu sali nie pobraliśmy
    // po raz drugi — serwer przysłał wszystko, co było potrzebne.
    expect(state.map.seats[101]!.status, SeatStatus.held);
    expect(state.statusOf(state.map.seats[101]!), SeatStatus.held);
    expect(api.seatMapCalls, 1);
  });

  test('SEATS_NOT_IN_HALL odświeża plan sali', () async {
    final FakeApi api = FakeApi(
      onLock: (List<int> ids) => reply(<String, Object?>{
        'message': 'Wybrane miejsca nie należą do sali tego seansu.',
        'code': 'SEATS_NOT_IN_HALL',
        'context': <String, Object?>{'seat_ids': ids},
      }, 422),
    );
    final ProviderContainer container = containerFor(api);
    await load(container);

    await notifier(container).toggle(seat(container, 102));

    expect(current(container).notice!.code, 'SEATS_NOT_IN_HALL');
    expect(api.seatMapCalls, 2);
  });

  test('miejsce bez ceny nie wychodzi nawet na żądanie do serwera', () async {
    final FakeApi api = FakeApi();
    final ProviderContainer container = containerFor(api);
    await load(container);

    await notifier(container).toggle(seat(container, 107));

    expect(api.lockCalls, 0);
    expect(current(container).notice!.code, isNull);
    expect(current(container).notice!.message, contains('B3'));
  });

  test('sprzedanego miejsca nie da się kliknąć', () async {
    final FakeApi api = FakeApi();
    final ProviderContainer container = containerFor(api);
    await load(container);

    expect(current(container).canTap(seat(container, 105)), isFalse);
    await notifier(container).toggle(seat(container, 105));

    expect(api.lockCalls, 0);
    expect(current(container).notice, isNull);
  });

  test('limit miejsc pilnujemy u siebie, bez żądania do serwera', () async {
    final FakeApi api = FakeApi(maxSeats: 1)..cart = cartOf(<int>[103]);
    final ProviderContainer container = containerFor(api);
    await load(container);

    expect(current(container).isFull, isTrue);
    await notifier(container).toggle(seat(container, 102));

    expect(api.lockCalls, 0);
    expect(current(container).notice!.message, contains('najwyżej 1'));
  });

  test('wyczyszczenie wyboru zwalnia cały koszyk', () async {
    final FakeApi api = FakeApi(
      onDelete: (String path) => reply(cartOf(<int>[])),
    )..cart = cartOf(<int>[103]);
    final ProviderContainer container = containerFor(api);
    await load(container);

    await notifier(container).clear();

    expect(current(container).cart.isEmpty, isTrue);
    expect(api.deleteCalls, 1);
  });

  test('pusty koszyk nie wysyła żądania czyszczenia', () async {
    final FakeApi api = FakeApi();
    final ProviderContainer container = containerFor(api);
    await load(container);

    await notifier(container).clear();

    expect(api.deleteCalls, 0);
  });

  test('rozpoczęta płatność zamraża plan sali', () async {
    final Map<String, Object?> withPending = cartOf(<int>[103]);
    (withPending['data']!
        as Map<String, Object?>)['pending_booking'] = <String, Object?>{
      'reference': '01K6ABCDEFGHJKMNPQRSTVWXYZ',
      'expires_at': '2026-09-28T15:45:52+00:00',
      'expires_in_seconds': 300,
    };
    final FakeApi api = FakeApi()..cart = withPending;
    final ProviderContainer container = containerFor(api);
    await load(container);

    expect(current(container).canTap(seat(container, 102)), isFalse);
    await notifier(container).toggle(seat(container, 102));

    expect(api.lockCalls, 0);
    expect(current(container).notice!.message, contains('Płatność'));
  });

  test('komunikat da się schować', () async {
    final FakeApi api = FakeApi();
    final ProviderContainer container = containerFor(api);
    await load(container);
    await notifier(container).toggle(seat(container, 107));
    expect(current(container).notice, isNotNull);

    notifier(container).dismissNotice();

    expect(current(container).notice, isNull);
  });
}
