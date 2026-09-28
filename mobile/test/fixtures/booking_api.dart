// Atrapa serwera dla ścieżki zakupowej — wspólna dla testów stanu i ekranu.
//
// Plan sali i konfiguracja idą z fikstur (prawdziwe odpowiedzi z rozpoznania),
// a odpowiedź na operacje koszyka podaje test. Dzięki jednemu miejscu oba
// zestawy testów widzą DOKŁADNIE ten sam serwer; wcześniej atrapa była
// przepisana w dwóch plikach i zaczynały się rozjeżdżać.

import 'dart:convert';

import 'package:cinema/models/seat_map.dart';
import 'package:http/http.dart' as http;

import 'fixtures.dart';

const int testScreeningId = 380;

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
        'formatted':
            '${(total / 100).toStringAsFixed(2).replaceAll('.', ',')} zł',
      },
      'expires_at': seats.isEmpty ? null : '2026-09-28T15:40:52+00:00',
      'expires_in_seconds': seats.isEmpty ? null : 540,
      'pending_booking': null,
    },
    'meta': <String, Object?>{'screening_id': testScreeningId},
  };
}

class FakeBookingApi {
  FakeBookingApi({
    this.onLock,
    this.onDelete,
    this.onSeatMap,
    this.maxSeats = 10,
  });

  final http.Response Function(List<int> seatIds)? onLock;
  final http.Response Function(String path)? onDelete;
  final http.Response Function()? onSeatMap;

  /// Limit miejsc podawany w `client-config` — tak podstawiamy go w testach,
  /// bo setter `state` notifiera jest `@protected` (pułapka DB).
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
      return onSeatMap?.call() ?? reply(envelope('seat_map'));
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
