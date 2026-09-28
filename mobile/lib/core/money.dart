// Pieniądze formatuje serwer (decyzja 23).
//
// API zwraca {amount w groszach, currency, formatted}. Aplikacja NIE składa
// własnego napisu z kwoty: separator, spacja przed walutą i zaokrąglenia mają
// być identyczne w mailu, w PDF-ie, w panelu, w SPA i na telefonie.

import 'package:cinema/core/json.dart';

class Money {
  const Money({
    required this.amount,
    required this.currency,
    required this.formatted,
  });

  factory Money.fromJson(Map<String, Object?> json, String where) => Money(
    amount: jsonInt(json, 'amount', where),
    currency: jsonString(json, 'currency', where),
    formatted: jsonString(json, 'formatted', where),
  );

  /// Grosze — do sum kontrolnych i porównań, nigdy do wyświetlania.
  final int amount;
  final String currency;

  /// Gotowy napis z serwera, np. `35,00 zł`.
  final String formatted;

  @override
  String toString() => formatted;
}
