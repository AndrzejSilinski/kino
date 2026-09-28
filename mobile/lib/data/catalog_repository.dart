// Katalog: kina, dni z repertuarem, repertuar dnia, szczegóły seansu.
//
// Wszystkie te odczyty są publiczne (bez tokenu), a serwer cachuje je w Redisie
// (decyzja 31), więc aplikacja nie buduje własnej pamięci podręcznej. Pilnuje
// tylko, żeby jedno wejście na ekran dawało jedno żądanie — tym zajmują się
// providery Riverpoda.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/models/cinema.dart';
import 'package:cinema/models/screening.dart';

class CatalogRepository {
  const CatalogRepository(this._api);

  /// Bezpiecznik pętli po stronach. Repertuar dnia ma 50 pozycji na stronę,
  /// więc pięć stron to 250 seansów jednego dnia w jednym kinie — grubo ponad
  /// to, co możliwe. Gdyby serwer kiedyś zapętlił `links.next`, aplikacja i tak
  /// się zatrzyma.
  static const int maxPages = 5;

  final ApiClient _api;

  Future<List<CityCinemas>> cinemas() async {
    final List<Object?> items = await _api.getList('/cinemas');
    return items
        .map((Object? item) => CityCinemas.fromJson(jsonMap(item, 'cinemas')))
        .toList(growable: false);
  }

  Future<List<ScreeningDate>> screeningDates(String cinemaSlug) async {
    final List<Object?> items = await _api.getList(
      '/cinemas/$cinemaSlug/screening-dates',
    );
    return items
        .map(
          (Object? item) =>
              ScreeningDate.fromJson(jsonMap(item, 'screening-dates')),
        )
        .toList(growable: false);
  }

  /// Repertuar dnia. Lista jest paginowana (50 na stronę), więc dobieramy
  /// kolejne strony, dopóki serwer je zapowiada.
  Future<List<ScreeningSummary>> screenings({
    required String cinemaSlug,
    required String date,
  }) async {
    final List<ScreeningSummary> all = <ScreeningSummary>[];
    for (int page = 1; page <= maxPages; page++) {
      final Map<String, Object?> envelope = await _api.getEnvelope(
        '/cinemas/$cinemaSlug/screenings',
        query: <String, String>{'date': date, 'page': '$page'},
      );
      const String where = 'screenings';
      all.addAll(
        jsonList(envelope['data'], where).map(
          (Object? item) => ScreeningSummary.fromJson(jsonMap(item, where)),
        ),
      );
      final Map<String, Object?> meta = jsonChild(envelope, 'meta', where);
      if (page >= jsonInt(meta, 'last_page', '$where.meta')) {
        break;
      }
    }
    return List<ScreeningSummary>.unmodifiable(all);
  }

  Future<ScreeningDetail> screening(int id) async =>
      ScreeningDetail.fromJson(await _api.getJson('/screenings/$id'));
}
