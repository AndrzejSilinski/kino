// Koszyk: miejsca trzymane przez MOJĄ sesję zakupową plus wycena z serwera.
//
// Każda operacja na blokadach (GET, POST, DELETE jednego miejsca, DELETE
// całości) zwraca ten sam kształt — cały aktualny koszyk, nie samo
// potwierdzenie. Potwierdzone rozpoznaniem fazy 2 i wykorzystane wprost:
// aplikacja NIE dokłada niczego do koszyka po swojemu, tylko zastępuje go
// tym, co przyszło z serwera (decyzja 287). Blokada jest all-or-nothing,
// więc zgadywanie wyniku i tak byłoby błędne w połowie przypadków.
//
// Kwot nie liczymy u siebie: `total` przychodzi z serwera razem z gotowym
// napisem (decyzja 23). Klient nie ma jak wpłynąć na cenę.

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/core/money.dart';
import 'package:cinema/models/seat_map.dart';

class CartSeat {
  const CartSeat({
    required this.seatId,
    required this.row,
    required this.number,
    required this.label,
    required this.type,
    required this.category,
    required this.price,
    required this.lockExpiresAt,
  });

  factory CartSeat.fromJson(Map<String, Object?> json) {
    const String where = 'cart.seats[]';
    return CartSeat(
      seatId: jsonInt(json, 'seat_id', where),
      row: jsonString(json, 'row', where),
      number: jsonInt(json, 'number', where),
      label: jsonString(json, 'label', where),
      type: jsonString(json, 'type', where),
      category: SeatCategory.fromJson(
        jsonChild(json, 'category', where),
        '$where.category',
      ),
      price: Money.fromJson(jsonChild(json, 'price', where), '$where.price'),
      lockExpiresAt: CinemaTime.parse(
        jsonString(json, 'lock_expires_at', where),
      ),
    );
  }

  final int seatId;
  final String row;
  final int number;
  final String label;
  final String type;
  final SeatCategory category;
  final Money price;

  /// Moment wygaśnięcia blokady TEGO miejsca.
  ///
  /// Uwaga: serwer wysyła go w UTC (`+00:00`), a godziny seansu w strefie kina
  /// (pułapka CY). Do odliczania na ekranie służy `expires_in_seconds`
  /// z koszyka, nie różnica dat na telefonie.
  final CinemaTime lockExpiresAt;
}

/// Rozpoczęta płatność za miejsca z tego koszyka.
///
/// Gdy to pole nie jest puste, plan sali musi być zamrożony: blokady należą do
/// rezerwacji, `DELETE` ich nie zwolni, a dobranie miejsca skończy się błędem
/// przy kolejnym checkoucie. Stan podaje serwer, bo tylko on go zna — po
/// zamknięciu aplikacji nie ma czego zgadywać (płatność dochodzi w bloku H).
class PendingBooking {
  const PendingBooking({
    required this.reference,
    required this.expiresAt,
    required this.expiresInSeconds,
  });

  factory PendingBooking.fromJson(Map<String, Object?> json) {
    const String where = 'cart.pending_booking';
    return PendingBooking(
      reference: jsonString(json, 'reference', where),
      expiresAt: CinemaTime.parse(jsonString(json, 'expires_at', where)),
      expiresInSeconds: jsonInt(json, 'expires_in_seconds', where),
    );
  }

  final String reference;
  final CinemaTime expiresAt;
  final int expiresInSeconds;
}

class Cart {
  const Cart({
    required this.seats,
    required this.seatsCount,
    required this.total,
    this.expiresAt,
    this.expiresInSeconds,
    this.pendingBooking,
  });

  factory Cart.fromJson(Map<String, Object?> data) {
    const String where = 'cart';
    final Object? expiresAt = data['expires_at'];
    final Object? pending = data['pending_booking'];
    return Cart(
      seats: jsonList(data['seats'], '$where.seats')
          .map((Object? item) => CartSeat.fromJson(jsonMap(item, where)))
          .toList(growable: false),
      seatsCount: jsonInt(data, 'seats_count', where),
      total: Money.fromJson(jsonChild(data, 'total', where), '$where.total'),
      expiresAt: expiresAt == null
          ? null
          : CinemaTime.parse(jsonString(data, 'expires_at', where)),
      expiresInSeconds: jsonIntOrNull(data, 'expires_in_seconds', where),
      pendingBooking: pending == null
          ? null
          : PendingBooking.fromJson(jsonMap(pending, '$where.pending_booking')),
    );
  }

  /// Koszyk przed pierwszym żądaniem — żeby ekran nie musiał obsługiwać `null`.
  static const Cart empty = Cart(
    seats: <CartSeat>[],
    seatsCount: 0,
    total: Money(amount: 0, currency: 'PLN', formatted: '0,00 zł'),
  );

  final List<CartSeat> seats;

  /// Licznik z serwera. Trzymamy go osobno od `seats.length` celowo: gdyby
  /// kiedyś się rozjechały, wiemy o tym od razu, zamiast pokazywać swoją wersję.
  final int seatsCount;

  final Money total;

  /// Koszyk wygasa razem z NAJWCZEŚNIEJSZĄ blokadą — nie ze średnią ani
  /// z najpóźniejszą.
  final CinemaTime? expiresAt;

  /// Sekundy do wygaśnięcia, policzone przez serwer. Do odliczania używamy ich,
  /// a nie różnicy dat: zegar telefonu bywa przestawiony (decyzja 288).
  final int? expiresInSeconds;

  final PendingBooking? pendingBooking;

  bool get isEmpty => seatsCount == 0;

  /// Identyfikatory moich miejsc — to one decydują, co plan sali maluje jako
  /// „moje” (decyzja 285).
  Set<int> get seatIds => seats.map((CartSeat seat) => seat.seatId).toSet();

  /// Czy licznik z serwera zgadza się z listą.
  bool get isConsistent => seatsCount == seats.length;
}
