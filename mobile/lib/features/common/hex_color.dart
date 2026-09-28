// Kolor kategorii cenowej przychodzi z panelu jako `#RRGGBB`.
//
// Jedno miejsce na to zamienianie, bo kolory kategorii pokazuje teraz i cennik
// seansu, i plan sali, i legenda planu. Zły albo pusty zapis NIE może wywrócić
// ekranu — wtedy bierzemy kolor zapasowy z motywu (decyzja 292).

import 'package:flutter/material.dart';

final RegExp _hex = RegExp(r'^#([0-9a-fA-F]{6})$');

Color colorFromHex(String? value, Color fallback) {
  if (value == null) {
    return fallback;
  }
  final RegExpMatch? match = _hex.firstMatch(value.trim());
  if (match == null) {
    return fallback;
  }
  return Color(0xFF000000 | int.parse(match.group(1)!, radix: 16));
}
