// Powiadomienie sprowadzone do własnego kształtu — i adres, który przyszedł
// z sieci.
//
// Połowa tego pliku to jeden temat: `data.url`. Wiadomość FCM przychodzi spoza
// aplikacji, a sekcja `data` jest dowolna — mając sam token urządzenia, da się
// wysłać w niej cokolwiek. Gdyby aplikacja otwierała to bez sprawdzenia,
// kliknięcie w powiadomienie byłoby wejściem, przez które można zaprowadzić
// użytkownika, gdzie się chce (decyzja 351).

import 'package:cinema/core/push.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ścieżka z powiadomienia', () {
    test('przyjmuje adres rezerwacji — ten sam co w SPA', () {
      expect(
        PushNotification.appPath('/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ'),
        '/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
      );
      expect(PushNotification.appPath('/'), '/');
      expect(PushNotification.appPath('  /bookings  '), '/bookings');
    });

    test('odrzuca adres prowadzący poza aplikację', () {
      // `//host` to adres bez schematu — przeglądarka i wiele bibliotek
      // potraktuje go jako CUDZY serwer, mimo że zaczyna się od ukośnika.
      expect(PushNotification.appPath('//kino.example.com/bookings'), isNull);
      expect(PushNotification.appPath('https://kino.example.com'), isNull);
      expect(PushNotification.appPath('intent://scan#Intent;end'), isNull);
      expect(PushNotification.appPath('javascript:alert(1)'), isNull);
      expect(PushNotification.appPath('bookings/01M2'), isNull);
    });

    test('odrzuca wyjście w górę i znaki spoza ścieżki', () {
      expect(PushNotification.appPath('/bookings/../admin'), isNull);
      expect(PushNotification.appPath('/bookings?token=abc'), isNull);
      expect(PushNotification.appPath('/bookings#top'), isNull);
      expect(PushNotification.appPath('/bookings 01M2'), isNull);
      expect(PushNotification.appPath('/bookings\nSet-Cookie: a=b'), isNull);
    });

    test('odrzuca to, co nie jest napisem albo jest za długie', () {
      expect(PushNotification.appPath(null), isNull);
      expect(PushNotification.appPath(7), isNull);
      expect(PushNotification.appPath(''), isNull);
      expect(PushNotification.appPath('/${'a' * 300}'), isNull);
    });
  });

  group('składanie powiadomienia', () {
    test('bierze typ i ścieżkę z sekcji data', () {
      final PushNotification notification = PushNotification.fromData(
        <String, Object?>{
          'type': 'booking.paid',
          'url': '/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
        },
        title: 'Płatność przyjęta',
        body: 'Skazani na Shawshank, jutro 18:30',
      );

      expect(notification.type, 'booking.paid');
      expect(notification.path, '/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ');
      expect(notification.title, 'Płatność przyjęta');
      expect(notification.hasText, isTrue);
    });

    test('powiadomienie bez treści da się rozpoznać', () {
      // Wiadomość z samą sekcją `data` (powiadomienie ciche) nie ma czego
      // pokazać — ekran na pierwszym planie musi to wiedzieć.
      final PushNotification notification = PushNotification.fromData(
        <String, Object?>{'type': 'booking.cancelled', 'url': '/bookings'},
      );

      expect(notification.hasText, isFalse);
      expect(notification.type, 'booking.cancelled');
    });

    test('brak typu i zły adres nie wywracają powiadomienia', () {
      // Nieznany kształt ma się najwyżej nie otworzyć. Wyjątek przy starcie
      // aplikacji z powiadomienia byłby awarią na oczach użytkownika.
      final PushNotification notification = PushNotification.fromData(
        <String, Object?>{'url': 'https://kino.example.com', 'type': 7},
        title: '   ',
      );

      expect(notification.type, PushNotification.unknownType);
      expect(notification.path, isNull);
      expect(notification.title, isNull);
    });
  });
}
