// Repozytorium koszyka na prawdziwych kopertach z rozpoznania fazy 2.
//
// Test pilnuje adresów i kształtu żądań (to od nich zależy, czy blokada trafi
// do właściwej sesji) oraz tego, że konflikt 409 dochodzi do aplikacji razem
// z listą zajętych miejsc — bez niej ekran musiałby pobierać cały plan sali.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/booking_repository.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

final List<http.Request> sent = <http.Request>[];

BookingRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => BookingRepository(
  ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async {
      sent.add(request);
      return handler(request);
    }),
  ),
);

http.Response fixtureResponse(String name, [int status = 200]) => http.Response(
  jsonEncode(envelope(name)),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

void main() {
  setUp(sent.clear);

  test('pobiera plan sali z właściwego adresu', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('seat_map'),
    );

    final SeatMap plan = await repository.seatMap(380);

    expect(sent.single.method, 'GET');
    expect(sent.single.url.path, '/api/v1/screenings/380/seat-map');
    expect(plan.seats.length, 8);
    expect(plan.version, 7);
  });

  test('pobiera koszyk', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('cart'),
    );

    final Cart cart = await repository.cart(380);

    expect(sent.single.method, 'GET');
    expect(sent.single.url.path, '/api/v1/screenings/380/seat-locks');
    expect(cart.seatsCount, 1);
  });

  test('blokuje miejsca listą seat_ids', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('cart', 201),
    );

    await repository.lock(380, <int>[103, 104]);

    expect(sent.single.method, 'POST');
    expect(sent.single.url.path, '/api/v1/screenings/380/seat-locks');
    expect(jsonDecode(sent.single.body), <String, Object?>{
      'seat_ids': <int>[103, 104],
    });
  });

  test('zwalnia jedno miejsce i cały koszyk', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('cart_empty'),
    );

    await repository.releaseSeat(380, 103);
    await repository.releaseAll(380);

    expect(sent.first.method, 'DELETE');
    expect(sent.first.url.path, '/api/v1/screenings/380/seat-locks/103');
    expect(sent.last.method, 'DELETE');
    expect(sent.last.url.path, '/api/v1/screenings/380/seat-locks');
  });

  test('konflikt 409 niesie kod i identyfikatory zajętych miejsc', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => http.Response(
        jsonEncode(envelope('seat_conflict')),
        409,
        headers: <String, String>{'content-type': 'application/json'},
      ),
    );

    await expectLater(
      repository.lock(380, <int>[104]),
      throwsA(
        isA<ApiError>()
            .having(
              (ApiError error) => error.code,
              'code',
              ApiError.seatsUnavailable,
            )
            .having((ApiError error) => error.seatIds, 'seatIds', <int>[104])
            .having(
              (ApiError error) => error.message,
              'message',
              contains('A4'),
            ),
      ),
    );
  });

  test('SEATS_NOT_IN_HALL dochodzi jako 422 z identyfikatorami', () async {
    final BookingRepository repository = repositoryFor(
      (http.Request request) => http.Response(
        jsonEncode(<String, Object?>{
          'message': 'Wybrane miejsca nie należą do sali tego seansu.',
          'code': 'SEATS_NOT_IN_HALL',
          'context': <String, Object?>{
            'seat_ids': <int>[999999999],
          },
        }),
        422,
        headers: <String, String>{'content-type': 'application/json'},
      ),
    );

    await expectLater(
      repository.lock(380, <int>[999999999]),
      throwsA(
        isA<ApiError>()
            .having(
              (ApiError error) => error.code,
              'code',
              ApiError.seatsNotInHall,
            )
            .having((ApiError error) => error.status, 'status', 422),
      ),
    );
  });
}
