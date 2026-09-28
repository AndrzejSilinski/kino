// Checkout i rezerwacje: jedyne miejsce w aplikacji, które zna te adresy.
//
// Checkout NIE WYSYŁA ŻADNYCH DANYCH w ciele — ani kwoty, ani listy miejsc.
// Miejsca wynikają z blokad przypisanych do sesji zakupowej, a kwota z cennika
// seansu. To celowe i warte zapamiętania: klient nie ma jak wpłynąć na to, ile
// zapłaci, bo nie ma czego podać (decyzja 312).
//
// Potwierdzone rozpoznaniem fazy 3:
//   - 201 przy pierwszym wywołaniu, 200 przy powtórzeniu na NIEZMIENIONYM
//     koszyku — i to jest droga powrotu do przerwanej płatności,
//   - 409 BOOKING_ALREADY_PENDING, gdy koszyk się w międzyczasie zmienił;
//     w `context.booking_reference` przychodzi numer rezerwacji do powrotu,
//   - 422 EMPTY_CART, gdy koszyk jest pusty,
//   - 401, gdy nie ma tokenu (rezerwacja musi mieć właściciela).
//
// Odczytu rezerwacji tu NIE MA, choć ścieżka płatności go potrzebuje: adres
// `/bookings/{reference}` należy do historii zakupów i ma jednego właściciela
// — `BookingsRepository` (decyzja 326). Dwa repozytoria znające ten sam adres
// to dwa miejsca do poprawienia, gdy zmieni się kontrakt.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/checkout.dart';

class CheckoutRepository {
  const CheckoutRepository(this._api);

  final ApiClient _api;

  /// Zamienia koszyk w rezerwację i zwraca dane do zapłaty.
  Future<Checkout> start(int screeningId) async => Checkout.fromJson(
    await _api.postJson('/screenings/$screeningId/booking'),
  );

  /// Rezygnacja z rozpoczętej płatności.
  ///
  /// Miejsca wracają do sprzedaży od razu, a nie po oknie płatności — dlatego
  /// warto dać klientowi ten przycisk zamiast kazać mu czekać. Rezerwacja
  /// zostaje w historii jako anulowana, a miejsca DONIESIONE obok niej zostają
  /// w koszyku (potwierdzone rozpoznaniem).
  Future<Booking> cancelPayment(String reference) async =>
      Booking.fromJson(await _api.deleteJson('/bookings/$reference/payment'));
}
