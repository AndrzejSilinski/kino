// Adresy aplikacji i to, czy `Routes.knows` naprawdę je zna.
//
// `knows` jest świadomą drugą kopią wzorców tras (decyzja 355) i właśnie
// dlatego ma własny test — kopia bez strażnika rozjeżdża się przy pierwszej
// nowej trasie, a objawem byłoby coś, czego nikt nie kojarzy z routerem:
// kliknięcie w powiadomienie przestaje otwierać właściwy ekran.

import 'dart:io';

import 'package:cinema/router.dart';
import 'package:flutter_test/flutter_test.dart';

/// Ile tras deklaruje `lib/router.dart`.
///
/// Liczymy je w ŹRÓDLE, a nie przez API `GoRouter`-a, bo chodzi o pytanie
/// „czy ktoś dopisał trasę", a nie „co router potrafi dopasować". Gdy ten
/// test padnie, odpowiedź jest zawsze ta sama: doszła trasa, więc albo
/// uzupełnij `Routes.knows`, albo świadomie zostaw ją poza deep linkami
/// i podnieś tę liczbę.
const int declaredRoutes = 12;

void main() {
  test('każda trasa aplikacji jest rozpoznawana jako adres wewnętrzny', () {
    for (final String path in <String>[
      Routes.home,
      Routes.login,
      Routes.register,
      Routes.cinemas,
      Routes.cinema('gdansk-kino-baltyk'),
      Routes.screening(338),
      Routes.seats(338),
      Routes.checkout(338),
      Routes.account,
      Routes.bookings,
      Routes.booking('01M2R0FF9TF8JNNQ9GBNZJ3TDQ'),
      Routes.diagnostics,
    ]) {
      expect(Routes.knows(path), isTrue, reason: 'nie rozpoznano $path');
    }
  });

  test('liczba tras się nie zmieniła bez uzupełnienia knows()', () {
    final String source = File('lib/router.dart').readAsStringSync();

    expect(
      'GoRoute('.allMatches(source).length,
      declaredRoutes,
      reason:
          'Doszła albo zniknęła trasa. Uzupełnij Routes.knows() i listę '
          'w teście wyżej, potem popraw tę liczbę.',
    );
  });

  test('adresy spoza aplikacji nie są rozpoznawane', () {
    for (final String path in <String>[
      '/czego-tu-nie-ma',
      '/bookings/za-krotki',
      '/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQX',
      '/screenings/abc',
      '/screenings/338/platnosc',
      '/cinemas/GDANSK',
      '/account/haslo',
    ]) {
      expect(Routes.knows(path), isFalse, reason: 'rozpoznano $path');
    }
  });
}
