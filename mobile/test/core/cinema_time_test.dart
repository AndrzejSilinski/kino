// Godziny seansów przychodzą w strefie KINA i mają być pokazane dosłownie.
// Te testy pilnują, żeby strefa telefonu nigdy ich nie przesunęła.

import 'package:cinema/core/cinema_time.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('rozkłada ISO 8601 z przesunięciem na czas ścienny i strefę', () {
    final CinemaTime time = CinemaTime.parse('2026-09-18T19:30:00+02:00');

    expect(time.date, '2026-09-18');
    expect(time.time, '19:30');
    expect(time.offset, const Duration(hours: 2));
  });

  test('czas ścienny nie zależy od strefy telefonu', () {
    final CinemaTime time = CinemaTime.parse('2026-01-05T09:05:00+01:00');

    // Wartości pochodzą wprost z napisu, bez żadnego przeliczania.
    expect(time.wallClock.hour, 9);
    expect(time.wallClock.minute, 5);
    expect(time.wallClock.isUtc, isTrue);
  });

  test('moment na osi czasu uwzględnia przesunięcie', () {
    final CinemaTime time = CinemaTime.parse('2026-09-18T19:30:00+02:00');

    expect(time.instant, DateTime.utc(2026, 9, 18, 17, 30));
  });

  test('obsługuje Z oraz przesunięcie ujemne bez dwukropka', () {
    expect(CinemaTime.parse('2026-09-18T19:30:00Z').offset, Duration.zero);
    expect(
      CinemaTime.parse('2026-09-18T19:30:00-0330').offset,
      const Duration(hours: -3, minutes: -30),
    );
  });

  test('podaje dzień tygodnia i pełny opis po polsku', () {
    final CinemaTime time = CinemaTime.parse('2026-09-18T19:30:00+02:00');

    expect(time.weekday, 'piątek');
    expect(time.full, 'piątek, 18.09, 19:30');
  });

  test('odrzuca czas bez przesunięcia strefy', () {
    expect(() => CinemaTime.parse('2026-09-18 19:30'), throwsFormatException);
  });
}
