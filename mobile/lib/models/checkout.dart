// Odpowiedź checkoutu: rezerwacja plus wszystko, czego potrzeba do zapłaty.
//
// Kształt potwierdzony rozpoznaniem fazy 3 na żywym serwerze w trybie
// testowym Stripe'a.
//
// Dwie rzeczy, które wyglądają na drobiazg, a nie są:
//
// 1. KLUCZ PUBLICZNY PRZYCHODZI Z SERWERA, nie z parametru buildu (decyzja 310).
//    Dzięki temu podmiana konta Stripe nie wymaga wydania nowej wersji
//    aplikacji mobilnej — a wersja w sklepie żyje u ludzi miesiącami. Tak samo
//    robi SPA, więc oba klienty biorą klucz z jednego miejsca.
// 2. `client_secret` to JEDYNA wartość, jaką aplikacja dostaje od Stripe'a.
//    Nie jest sekretem konta (tym jest klucz tajny, który nigdy nie opuszcza
//    serwera), ale jest przepustką do TEJ płatności — więc nie trafia do
//    logów, raportów ani komunikatów o błędach.

import 'package:cinema/core/json.dart';
import 'package:cinema/models/booking.dart';

/// Status intencji płatności po stronie Stripe'a.
///
/// Aplikacja rozgałęzia się na dwa pytania: „czy trzeba jeszcze zapłacić”
/// i „czy pieniądze już poszły”. Reszta wartości jest informacyjna, więc
/// trzymamy surowy napis zamiast wyliczenia — lista statusów Stripe'a jest
/// długa i zmienia się niezależnie od nas (decyzja 311).
class PaymentIntentInfo {
  const PaymentIntentInfo({
    required this.provider,
    required this.publishableKey,
    required this.clientSecret,
    required this.status,
    required this.expiresAt,
    required this.expiresInSeconds,
  });

  factory PaymentIntentInfo.fromJson(Map<String, Object?> json) {
    const String where = 'checkout.payment';
    return PaymentIntentInfo(
      provider: jsonString(json, 'provider', where),
      publishableKey: jsonString(json, 'publishable_key', where),
      clientSecret: jsonString(json, 'client_secret', where),
      status: jsonString(json, 'status', where),
      expiresAt: jsonString(json, 'expires_at', where),
      expiresInSeconds: jsonInt(json, 'expires_in_seconds', where),
    );
  }

  final String provider;
  final String publishableKey;
  final String clientSecret;

  /// Np. `requires_payment_method`, `succeeded`.
  final String status;

  final String expiresAt;

  /// Sekundy do końca okna płatności — z serwera, nie z zegara telefonu.
  final int expiresInSeconds;

  bool get isPaid => status == 'succeeded';

  bool get needsPaymentMethod => status == 'requires_payment_method';

  /// W logach i komunikatach nigdy nie pokazujemy sekretu ani klucza.
  @override
  String toString() => 'PaymentIntentInfo($provider, status: $status)';
}

class Checkout {
  const Checkout({required this.booking, required this.payment});

  factory Checkout.fromJson(Map<String, Object?> data) {
    const String where = 'checkout';
    return Checkout(
      booking: Booking.fromJson(jsonChild(data, 'booking', where)),
      payment: PaymentIntentInfo.fromJson(jsonChild(data, 'payment', where)),
    );
  }

  final Booking booking;
  final PaymentIntentInfo payment;

  @override
  String toString() => 'Checkout(${booking.reference}, ${payment.status})';
}
