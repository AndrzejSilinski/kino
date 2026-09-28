// Plan sali i koszyk — jedyne miejsce w aplikacji, które zna adresy blokad.
//
// Wszystkie te wywołania wymagają sesji zakupowej (`X-Session-Id`). Nagłówka
// NIE dokładamy tu ręcznie: robi to `ApiClient`, który zapamiętuje też każdy
// identyfikator przysłany przez serwer. To nie jest kosmetyka — rozpoznanie
// fazy 2 potwierdziło, że żądanie bez nagłówka dostaje NOWĄ sesję i blokada
// ląduje w koszyku, o którym aplikacja nic nie wie (pułapka CZ).
//
// Operacje zmieniające blokady mają limit 30 na minutę liczony SESJĄ (nie IP,
// bo cała galeria handlowa wychodzi do internetu jednym adresem). Limit
// zużywają też odrzucone żądania, więc repozytorium niczego nie ponawia —
// ponowienie jest zawsze decyzją użytkownika (decyzja 289).

import 'package:cinema/core/api_client.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/models/seat_map.dart';

class BookingRepository {
  const BookingRepository(this._api);

  final ApiClient _api;

  String _locks(int screeningId) => '/screenings/$screeningId/seat-locks';

  /// Pełny stan sali. To także droga powrotu po zerwaniu WebSocketa: klient
  /// pobiera stan przez REST, a potem nakłada na niego nowsze zdarzenia.
  /// Ta trasa NIE ma limitu zapytań właśnie dlatego (wymóg 1.3 zadania).
  Future<SeatMap> seatMap(int screeningId) async =>
      SeatMap.fromJson(await _api.getJson('/screenings/$screeningId/seat-map'));

  /// Mój koszyk na tym seansie wraz z wyceną i czasem do wygaśnięcia.
  Future<Cart> cart(int screeningId) async =>
      Cart.fromJson(await _api.getJson(_locks(screeningId)));

  /// Blokada miejsc. All-or-nothing: konflikt to 409 `SEATS_UNAVAILABLE`
  /// z listą zajętych foteli w `context.seat_ids`.
  Future<Cart> lock(int screeningId, List<int> seatIds) async => Cart.fromJson(
    await _api.postJson(
      _locks(screeningId),
      body: <String, Object?>{'seat_ids': seatIds},
    ),
  );

  /// Odkliknięcie jednego miejsca. Idempotentne — 200 z koszykiem także wtedy,
  /// gdy blokady już nie było (podwójne kliknięcie zdarza się nieustannie).
  Future<Cart> releaseSeat(int screeningId, int seatId) async =>
      Cart.fromJson(await _api.deleteJson('${_locks(screeningId)}/$seatId'));

  /// „Wyczyść wybór” — porzucenie całego koszyka na tym seansie.
  Future<Cart> releaseAll(int screeningId) async =>
      Cart.fromJson(await _api.deleteJson(_locks(screeningId)));
}
