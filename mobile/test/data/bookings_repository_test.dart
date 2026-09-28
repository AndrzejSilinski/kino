// Repozytorium historii zakupów na kopertach z rozpoznania fazy 4.
//
// Najważniejsze sprawdzenie: stronicujemy PO NUMERZE STRONY, a nie po adresach
// z `links` (decyzja 323) — te są zbudowane z hosta serwera i na telefonie
// wskazywałyby sam telefon.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/bookings_repository.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

final List<http.Request> sent = <http.Request>[];

BookingsRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => BookingsRepository(
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

  test('lista idzie na /bookings z numerem strony w zapytaniu', () async {
    final BookingsRepository repository = repositoryFor(
      (http.Request request) => json(envelope('bookings_page')),
    );

    final Paginated<Booking> page = await repository.list();

    expect(sent.single.method, 'GET');
    expect(sent.single.url.path, '/api/v1/bookings');
    expect(sent.single.url.queryParameters['page'], '1');
    expect(sent.single.url.queryParameters['per_page'], '15');
    expect(page.items, hasLength(2));
    expect(page.hasMore, isTrue);
  });

  test('druga strona to inny numer, a NIE adres z links', () async {
    final BookingsRepository repository = repositoryFor(
      (http.Request request) => json(envelope('bookings_page2')),
    );

    await repository.list(page: 2, perPage: 2);

    expect(sent.single.url.queryParameters['page'], '2');
    expect(sent.single.url.queryParameters['per_page'], '2');
    // Adres z `links.next` prowadził do localhost serwera; na telefonie
    // localhost to telefon, więc nigdy z niego nie korzystamy.
    expect(sent.single.url.host, 'localhost');
    expect(sent.single.url.path, '/api/v1/bookings');
  });

  test('szczegóły idą po reference i niosą bilety', () async {
    final BookingsRepository repository = repositoryFor(
      (http.Request request) => json(envelope('booking_tickets')),
    );

    final Booking booking = await repository.details(
      '01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
    );

    expect(sent.single.method, 'GET');
    expect(sent.single.url.path, '/api/v1/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ');
    expect(booking.tickets, hasLength(2));
    expect(booking.status, BookingStatus.paid);
  });

  test('bilety niosą adres kodu QR, a nie sam kod', () async {
    // Kodu biletu w API NIE MA i nie ma go też tutaj: gdyby był, aplikacja
    // mogłaby wygenerować kod QR sama, a wtedy przepustka na salę leżałaby
    // w pamięci telefonu.
    final BookingsRepository repository = repositoryFor(
      (http.Request request) => json(envelope('booking_tickets')),
    );

    final Booking booking = await repository.details(
      '01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
    );

    expect(booking.tickets.first.qrUrl, contains('/tickets/9/qr'));
    expect(jsonEncode(envelope('booking_tickets')), isNot(contains('"code"')));
  });
}
