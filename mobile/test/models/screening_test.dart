// Modele repertuaru powstały z prawdziwych odpowiedzi serwera zebranych
// w rozpoznaniu (test/fixtures/screenings_day.json, screening_detail.json).

import 'package:cinema/core/json.dart';
import 'package:cinema/models/screening.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

List<ScreeningSummary> day() =>
    fixtureList('screenings_day')
        .map((Object? item) => ScreeningSummary.fromJson(jsonMap(item, 'test')))
        .toList();

void main() {
  test('czyta pozycję repertuaru razem z salą, filmem i miejscami', () {
    final ScreeningSummary first = day().first;

    expect(first.id, 338);
    expect(first.startsAt.time, '11:00');
    expect(first.startsAt.date, '2026-09-18');
    expect(first.hallName, 'Sala A');
    expect(first.projectionTypeLabel, '2D');
    expect(first.languageVersionLabel, 'Napisy polskie');
    expect(first.movie.title, 'Oppenheimer');
    expect(first.seats.available, 89);
    expect(first.isBookable, isTrue);
    expect(first.unavailableReason, isNull);
  });

  test('wyprzedany seans podaje powód niedostępności', () {
    final ScreeningSummary soldOut = day().last;

    expect(soldOut.isSoldOut, isTrue);
    expect(soldOut.isBookable, isFalse);
    expect(soldOut.unavailableReason, 'Wyprzedane');
  });

  test('czas trwania filmu jest czytelny', () {
    expect(day().first.movie.duration, '3 godz.');
    expect(day().last.movie.duration, '1 godz. 45 min');
  });

  test('czyta szczegóły seansu z cennikiem i salą', () {
    final ScreeningDetail detail = ScreeningDetail.fromJson(
      fixtureMap('screening_detail'),
    );

    expect(detail.hall.cinema.name, 'Kino Bałtyk');
    expect(detail.hall.grid.rows, 9);
    expect(detail.hall.grid.columns, 12);
    expect(detail.movie.genres, <String>['dramat', 'historyczny']);
    expect(detail.prices.length, 2);
    expect(detail.prices.first.categoryName, 'Standardowe');
    expect(detail.prices.first.price.formatted, '17,60 zł');
  });

  test('najtańsza cena pochodzi z porównania groszy, nie napisów', () {
    final ScreeningDetail detail = ScreeningDetail.fromJson(
      fixtureMap('screening_detail'),
    );

    expect(detail.cheapest?.amount, 1760);
  });

  test('dni z repertuarem mają datę i liczbę seansów', () {
    final List<ScreeningDate> dates = fixtureList('screening_dates')
        .map((Object? item) => ScreeningDate.fromJson(jsonMap(item, 'test')))
        .toList();

    expect(dates.length, 3);
    expect(dates.first.date, '2026-09-18');
    expect(dates.first.screeningsCount, 8);
  });
}
