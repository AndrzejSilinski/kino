// Historia zakupów i bilety — jedyne miejsce w aplikacji, które zna te adresy.
//
// Adresy biletów (`/bookings/{reference}/tickets/...`) mają osobny, ciasny limit
// żądań: rozpoznanie fazy 4 pokazało `X-RateLimit-Limit: 30`, wspólny dla
// obrazów QR i PDF-a. Dlatego repozytorium NICZEGO nie ponawia, a ekran nie
// pobiera kodów w pętli — obraz raz pobrany zostaje w pamięci podręcznej
// Fluttera (klucz to adres), więc przewijanie listy biletów nie kosztuje
// kolejnych żądań.
//
// Rezerwacja jest zawsze adresowana przez `reference` (ULID), nigdy przez
// sekwencyjne id. Rozpoznanie potwierdziło, że `/bookings/1` oddaje 404
// `RESOURCE_NOT_FOUND` — czyli po numerach nie da się chodzić po cudzych
// zakupach, a samo 404 nawet nie potwierdza, czy coś takiego istnieje.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';

class BookingsRepository {
  const BookingsRepository(this._api);

  final ApiClient _api;

  /// Strona historii zakupów, od najnowszej rezerwacji.
  ///
  /// `getEnvelope`, a nie `getJson`: przy listach potrzebne jest całe `meta`,
  /// bo z niego bierzemy „czy jest jeszcze strona” (decyzja 323).
  Future<Paginated<Booking>> list({int page = 1, int perPage = 15}) async =>
      Paginated<Booking>.fromEnvelope(
        await _api.getEnvelope(
          '/bookings',
          query: <String, String>{'page': '$page', 'per_page': '$perPage'},
        ),
        'bookings',
        Booking.fromJson,
      );

  /// Szczegóły rezerwacji wraz z biletami i seansem.
  Future<Booking> details(String reference) async =>
      Booking.fromJson(await _api.getJson('/bookings/$reference'));
}
