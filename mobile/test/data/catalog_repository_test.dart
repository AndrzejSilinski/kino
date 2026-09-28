// Repozytorium katalogu na prawdziwych kopertach odpowiedzi: lista kin,
// dni, repertuar dnia (z paginacją) i szczegóły seansu.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/catalog_repository.dart';
import 'package:cinema/models/cinema.dart';
import 'package:cinema/models/screening.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

CatalogRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => CatalogRepository(
  ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async => handler(request)),
  ),
);

http.Response fixtureResponse(String name) => http.Response(
  jsonEncode(envelope(name)),
  200,
  headers: <String, String>{'content-type': 'application/json'},
);

void main() {
  test('pobiera kina pogrupowane po miastach', () async {
    final CatalogRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('cinemas'),
    );

    final List<CityCinemas> groups = await repository.cinemas();

    expect(groups.map((CityCinemas g) => g.city), <String>[
      'Gdańsk',
      'Warszawa',
    ]);
  });

  test('pobiera dni z repertuarem', () async {
    Uri? called;
    final CatalogRepository repository = repositoryFor((http.Request request) {
      called = request.url;
      return fixtureResponse('screening_dates');
    });

    final List<ScreeningDate> dates = await repository.screeningDates(
      'gdansk-kino-baltyk',
    );

    expect(dates.length, 3);
    expect(
      called.toString(),
      'http://localhost:8080/api/v1/cinemas/gdansk-kino-baltyk'
      '/screening-dates',
    );
  });

  test('pobiera repertuar dnia z parametrem date', () async {
    Uri? called;
    final CatalogRepository repository = repositoryFor((http.Request request) {
      called = request.url;
      return fixtureResponse('screenings_day');
    });

    final List<ScreeningSummary> items = await repository.screenings(
      cinemaSlug: 'gdansk-kino-baltyk',
      date: '2026-09-18',
    );

    expect(items.length, 2);
    expect(called?.queryParameters['date'], '2026-09-18');
  });

  test('dobiera kolejne strony repertuaru, gdy serwer je zapowiada', () async {
    int calls = 0;
    final CatalogRepository repository = repositoryFor((http.Request request) {
      calls++;
      final Map<String, Object?> body = envelope('screenings_day');
      final Map<String, Object?> meta = body['meta']! as Map<String, Object?>;
      meta['last_page'] = 2;
      meta['current_page'] = calls;
      return http.Response(
        jsonEncode(body),
        200,
        headers: <String, String>{'content-type': 'application/json'},
      );
    });

    final List<ScreeningSummary> items = await repository.screenings(
      cinemaSlug: 'gdansk-kino-baltyk',
      date: '2026-09-18',
    );

    expect(calls, 2);
    expect(items.length, 4);
  });

  test('pobiera szczegóły seansu', () async {
    final CatalogRepository repository = repositoryFor(
      (http.Request request) => fixtureResponse('screening_detail'),
    );

    final ScreeningDetail detail = await repository.screening(338);

    expect(detail.id, 338);
    expect(detail.hall.name, 'Sala A');
  });
}
