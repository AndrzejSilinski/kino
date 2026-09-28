import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/models/cinema.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fixtures.dart';

void main() {
  test('czyta kina pogrupowane po miastach', () {
    final List<CityCinemas> groups = fixtureList('cinemas')
        .map((Object? item) => CityCinemas.fromJson(jsonMap(item, 'cinemas')))
        .toList();

    expect(groups.length, 2);
    expect(groups.first.city, 'Gdańsk');
    expect(groups.first.cinemas.single.slug, 'gdansk-kino-baltyk');
    expect(groups.last.cinemas.length, 2);
    expect(groups.last.cinemas.first.address, 'ul. Złota 44');
  });

  test('brak pola w kinie to INVALID_RESPONSE ze ścieżką', () {
    try {
      Cinema.fromJson(<String, Object?>{'id': 1, 'slug': 'x'});
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.invalidResponse);
      expect(error.context['where'], 'cinema.name');
    }
  });
}
