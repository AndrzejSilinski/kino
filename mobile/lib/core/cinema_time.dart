// Czas w strefie KINA, wyświetlany dosłownie (decyzja 24).
//
// API zwraca godziny seansów w strefie kina, np. 2026-09-18T19:30:00+02:00.
// Gdyby aplikacja użyła zwykłego DateTime.parse, dostałaby moment w UTC,
// a toLocal() przeliczyłby go na strefę TELEFONU — użytkownik w innej strefie
// zobaczyłby inną godzinę seansu niż ta wydrukowana na bilecie (pułapka CP).
// Dlatego trzymamy godzinę ścienną osobno od przesunięcia strefy.

/// Godzina z API rozłożona na czas ścienny i przesunięcie strefy kina.
class CinemaTime {
  const CinemaTime({required this.wallClock, required this.offset});

  /// Rozkłada ISO 8601 z przesunięciem. Rzuca [FormatException] dla innych
  /// kształtów — kontrakt API gwarantuje przesunięcie przy każdej godzinie.
  factory CinemaTime.parse(String value) {
    final RegExpMatch? match = _pattern.firstMatch(value.trim());
    if (match == null) {
      throw FormatException('oczekiwano ISO 8601 z przesunięciem', value);
    }
    final String zone = match.group(7)!;
    return CinemaTime(
      wallClock: DateTime.utc(
        int.parse(match.group(1)!),
        int.parse(match.group(2)!),
        int.parse(match.group(3)!),
        int.parse(match.group(4)!),
        int.parse(match.group(5)!),
        int.parse(match.group(6) ?? '0'),
      ),
      offset: _parseOffset(zone),
    );
  }

  static final RegExp _pattern = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?'
    r'(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$',
  );

  static const List<String> _weekdays = <String>[
    'poniedziałek',
    'wtorek',
    'środa',
    'czwartek',
    'piątek',
    'sobota',
    'niedziela',
  ];

  /// Godzina tak, jak ma być pokazana: pola y/m/d/h/min są tym, co przysłał
  /// serwer. Znacznik UTC jest tylko po to, żeby Dart niczego nie przeliczał.
  final DateTime wallClock;

  /// Przesunięcie strefy kina wobec UTC (np. +2 h latem w Polsce).
  final Duration offset;

  /// Moment na osi czasu — do porównań i odliczania, nie do wyświetlania.
  DateTime get instant => wallClock.subtract(offset);

  /// `2026-09-18`
  String get date =>
      '${_pad(wallClock.year, 4)}-${_pad(wallClock.month, 2)}'
      '-${_pad(wallClock.day, 2)}';

  /// `19:30`
  String get time => '${_pad(wallClock.hour, 2)}:${_pad(wallClock.minute, 2)}';

  /// `piątek`
  String get weekday => _weekdays[wallClock.weekday - 1];

  /// `piątek, 18.09, 19:30` — jeden format dla repertuaru i biletów.
  String get full =>
      '$weekday, ${_pad(wallClock.day, 2)}.${_pad(wallClock.month, 2)}, $time';

  static Duration _parseOffset(String zone) {
    if (zone == 'Z') {
      return Duration.zero;
    }
    final String digits = zone.replaceAll(':', '');
    final int hours = int.parse(digits.substring(1, 3));
    final int minutes = int.parse(digits.substring(3, 5));
    final Duration value = Duration(hours: hours, minutes: minutes);
    return zone.startsWith('-') ? -value : value;
  }

  static String _pad(int value, int width) =>
      value.toString().padLeft(width, '0');

  @override
  String toString() => '$date $time';
}
