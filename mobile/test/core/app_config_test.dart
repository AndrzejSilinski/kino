// Adres API pochodzi z parametru buildu, więc jego składanie musi być pewne:
// literówka w --dart-define ma wyjść przy starcie, a token bearer nie może
// polecieć na obcy host (decyzja 257).

import 'package:cinema/core/app_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('AppConfig.parseBaseUrl', () {
    test('obcina końcowy ukośnik', () {
      final AppConfig config = AppConfig(
        AppConfig.parseBaseUrl('http://localhost:8080/'),
      );

      expect(config.apiBaseUrl.toString(), 'http://localhost:8080');
    });

    test('odrzuca adres bez http i https', () {
      expect(
        () => AppConfig.parseBaseUrl('ftp://localhost:8080'),
        throwsFormatException,
      );
    });

    test('odrzuca adres bez hosta', () {
      expect(() => AppConfig.parseBaseUrl('/api'), throwsFormatException);
    });

    test('odrzuca adres z parametrami zapytania', () {
      expect(
        () => AppConfig.parseBaseUrl('http://localhost:8080/?debug=1'),
        throwsFormatException,
      );
    });
  });

  group('AppConfig.apiUri', () {
    final AppConfig config = AppConfig(
      AppConfig.parseBaseUrl('http://localhost:8080'),
    );

    test('składa adres z prefiksem /api/v1', () {
      expect(
        config.apiUri('/client-config').toString(),
        'http://localhost:8080/api/v1/client-config',
      );
    });

    test('dokłada parametry zapytania', () {
      expect(
        config
            .apiUri(
              '/cinemas/gdansk/screenings',
              query: <String, String>{'date': '2026-09-18'},
            )
            .toString(),
        'http://localhost:8080/api/v1/cinemas/gdansk/screenings'
        '?date=2026-09-18',
      );
    });

    test('wymaga ścieżki zaczynającej się od ukośnika', () {
      expect(() => config.apiUri('client-config'), throwsArgumentError);
    });
  });

  group('AppConfig.isSameOrigin', () {
    final AppConfig config = AppConfig(
      AppConfig.parseBaseUrl('http://localhost:8080'),
    );

    test('przyjmuje adresy absolutne z naszego serwera', () {
      expect(
        config.isSameOrigin(
          Uri.parse('http://localhost:8080/storage/posters/x.jpg'),
        ),
        isTrue,
      );
    });

    test('odrzuca inny host albo port', () {
      expect(config.isSameOrigin(Uri.parse('http://example.com/x')), isFalse);
      expect(
        config.isSameOrigin(Uri.parse('http://localhost:9000/x')),
        isFalse,
      );
    });

    test('wykrywa połączenie bez TLS', () {
      expect(config.isCleartext, isTrue);
      expect(
        AppConfig(AppConfig.parseBaseUrl('https://kino.example')).isCleartext,
        isFalse,
      );
    });
  });
}
