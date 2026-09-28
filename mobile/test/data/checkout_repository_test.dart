// Repozytorium checkoutu na kopertach z rozpoznania fazy 3.
//
// Najważniejsze sprawdzenie w tym pliku: checkout NIE WYSYŁA NICZEGO w ciele.
// Ani kwoty, ani listy miejsc — klient nie ma jak wpłynąć na to, ile zapłaci.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/checkout_repository.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/checkout.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

final List<http.Request> sent = <http.Request>[];

CheckoutRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => CheckoutRepository(
  ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async {
      sent.add(request);
      return handler(request);
    }),
  ),
);

http.Response json(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

void main() {
  setUp(sent.clear);

  test('checkout idzie na właściwy adres i BEZ danych w ciele', () async {
    final CheckoutRepository repository = repositoryFor(
      (http.Request request) => json(envelope('checkout'), 201),
    );

    final Checkout checkout = await repository.start(380);

    expect(sent.single.method, 'POST');
    expect(sent.single.url.path, '/api/v1/screenings/380/booking');
    expect(sent.single.body, isEmpty);
    expect(checkout.booking.status, BookingStatus.pending);
  });

  test(
    'powtórzony checkout ze statusem 200 też jest poprawną odpowiedzią',
    () async {
      // Serwer oddaje wtedy TĘ SAMĄ rezerwację — to droga powrotu do przerwanej
      // płatności (potwierdzone rozpoznaniem).
      final CheckoutRepository repository = repositoryFor(
        (http.Request request) => json(envelope('checkout')),
      );

      final Checkout checkout = await repository.start(380);

      expect(checkout.booking.reference, '01M3MK53H15CAMZB8DE9AFF06Q');
    },
  );

  test('pusty koszyk daje EMPTY_CART', () async {
    final CheckoutRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'message': 'Koszyk jest pusty.',
        'code': 'EMPTY_CART',
      }, 422),
    );

    await expectLater(
      repository.start(380),
      throwsA(
        isA<ApiError>().having(
          (ApiError error) => error.code,
          'code',
          ApiError.emptyCart,
        ),
      ),
    );
  });

  test('409 niesie numer rezerwacji, do której trzeba wrócić', () async {
    final CheckoutRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'message': 'Masz już rozpoczętą płatność za te miejsca.',
        'code': 'BOOKING_ALREADY_PENDING',
        'context': <String, Object?>{
          'booking_reference': '01M3MK53H15CAMZB8DE9AFF06Q',
        },
      }, 409),
    );

    await expectLater(
      repository.start(380),
      throwsA(
        isA<ApiError>()
            .having(
              (ApiError error) => error.code,
              'code',
              ApiError.bookingAlreadyPending,
            )
            .having(
              (ApiError error) => error.bookingReference,
              'bookingReference',
              '01M3MK53H15CAMZB8DE9AFF06Q',
            ),
      ),
    );
  });

  test('szczegóły rezerwacji idą po reference, nie po numerze', () async {
    final CheckoutRepository repository = repositoryFor(
      (http.Request request) => json(envelope('booking_paid')),
    );

    final Booking booking = await repository.booking(
      '01M3MK53H15CAMZB8DE9AFF06Q',
    );

    expect(sent.single.method, 'GET');
    expect(sent.single.url.path, '/api/v1/bookings/01M3MK53H15CAMZB8DE9AFF06Q');
    expect(booking.status, BookingStatus.paid);
  });

  test('rezygnacja kasuje płatność, nie rezerwację', () async {
    final CheckoutRepository repository = repositoryFor(
      (http.Request request) => json(envelope('booking_cancelled')),
    );

    final Booking booking = await repository.cancelPayment(
      '01M3MK53H15CAMZB8DE9AFF06Q',
    );

    expect(sent.single.method, 'DELETE');
    // Podzasób „payment”: usuwamy rozpoczętą płatność, a rezerwacja zostaje
    // w historii jako anulowana.
    expect(
      sent.single.url.path,
      '/api/v1/bookings/01M3MK53H15CAMZB8DE9AFF06Q/payment',
    );
    expect(booking.status, BookingStatus.cancelled);
    expect(booking.cancellation, isNotNull);
  });
}
