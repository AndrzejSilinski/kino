// Konfiguracja klienta z `GET /api/v1/client-config` (cache publiczny 60 s).
//
// Świadomie NIE czytamy `push.firebase`: to konfiguracja aplikacji WEB (klucz
// API przeglądarki, VAPID). Aplikacja na Androidzie bierze konfigurację
// Firebase z `google-services.json` wkompilowanego w APK (decyzja 292), więc
// gdybyśmy jej tu użyli, telefon rejestrowałby się jako urządzenie webowe.
//
// Blok `push.android` (Etap 9, blok K) czytamy — ale NIE po to, żeby się nim
// skonfigurować (patrz wyżej), tylko żeby móc PORÓWNAĆ. Jeśli serwer wysyła
// z projektu „kino-prod", a aplikację zbudowano z pliku projektu „kino-test",
// rejestracja urządzenia przejdzie bez błędu, token będzie wyglądał poprawnie
// i wszystko będzie wyglądać dobrze — a powiadomienia po prostu nigdy nie
// dojdą, bo token z jednego projektu jest w drugim nieznany. To awaria bez
// żadnego komunikatu, najgorszy możliwy rodzaj; ekran diagnostyczny wykrywa ją
// przez porównanie tych wartości (blok M2). Blok bywa nieobecny i to NIE
// znaczy, że push nie działa (decyzja 354).

import 'package:cinema/core/json.dart';

class ClientConfig {
  const ClientConfig({
    required this.apiVersion,
    required this.realtime,
    required this.booking,
    required this.pushEnabled,
    this.android,
  });

  factory ClientConfig.fromJson(Map<String, Object?> data) {
    final Map<String, Object?> push = jsonChild(data, 'push', 'client-config');
    final Object? android = push['android'];
    return ClientConfig(
      apiVersion: jsonString(data, 'api_version', 'client-config'),
      realtime: RealtimeConfig.fromJson(
        jsonChild(data, 'realtime', 'client-config'),
      ),
      booking: BookingConfig.fromJson(
        jsonChild(data, 'booking', 'client-config'),
      ),
      pushEnabled: jsonBool(push, 'enabled', 'client-config.push'),
      // Bloku nie ma, gdy wdrożenie nie wypełniło zmiennych opisowych projektu
      // — wtedy nie mamy z czym porównywać i tyle. Gdyby przyszedł w innym
      // kształcie niż obiekt, to już błąd kontraktu i strażnik go zgłosi.
      android: android == null
          ? null
          : AndroidPushConfig.fromJson(
              jsonMap(android, 'client-config.push.android'),
            ),
    );
  }

  final String apiVersion;
  final RealtimeConfig realtime;
  final BookingConfig booking;
  final bool pushEnabled;

  /// Projekt Firebase, z którego wysyła SERWER — do porównania z tym,
  /// z którego zbudowano aplikację. Null, gdy serwer go nie podał.
  final AndroidPushConfig? android;
}

/// Dane projektu Firebase po stronie serwera (blok K).
class AndroidPushConfig {
  const AndroidPushConfig({
    required this.projectId,
    required this.appId,
    required this.packageName,
  });

  factory AndroidPushConfig.fromJson(Map<String, Object?> json) {
    const String where = 'client-config.push.android';
    return AndroidPushConfig(
      projectId: jsonString(json, 'project_id', where),
      appId: jsonString(json, 'app_id', where),
      packageName: jsonString(json, 'package_name', where),
    );
  }

  /// Identyfikator projektu, np. `kino-prod`. Jawny z natury — jest w każdym
  /// `google-services.json` rozprowadzanym razem z aplikacją.
  final String projectId;

  /// Identyfikator aplikacji Android w tym projekcie (`1:…:android:…`).
  final String appId;

  /// Nazwa pakietu, dla której wydano `app_id`, np. `pl.silinski.cinema`.
  final String packageName;
}

/// Dane połączenia WebSocket. Klucz Reverba jest jawny z natury (trafia do
/// adresu połączenia), ale i tak nie wypisujemy go w logach.
class RealtimeConfig {
  const RealtimeConfig({
    required this.broadcaster,
    required this.key,
    required this.path,
  });

  factory RealtimeConfig.fromJson(Map<String, Object?> json) {
    return RealtimeConfig(
      broadcaster: jsonString(json, 'broadcaster', 'client-config.realtime'),
      key: jsonString(json, 'key', 'client-config.realtime'),
      path: jsonString(json, 'path', 'client-config.realtime'),
    );
  }

  final String broadcaster;
  final String key;
  final String path;
}

/// Parametry koszyka: TTL blokady, limit miejsc i okno płatności. Serwer jest
/// źródłem prawdy — aplikacja nie zna tych liczb z kodu (timer w bloku F).
class BookingConfig {
  const BookingConfig({
    required this.seatLockTtl,
    required this.maxSeatsPerSession,
    required this.paymentWindow,
  });

  factory BookingConfig.fromJson(Map<String, Object?> json) {
    const String where = 'client-config.booking';
    return BookingConfig(
      seatLockTtl: Duration(
        seconds: jsonInt(json, 'seat_lock_ttl_seconds', where),
      ),
      maxSeatsPerSession: jsonInt(json, 'max_seats_per_session', where),
      paymentWindow: Duration(
        seconds: jsonInt(json, 'payment_window_seconds', where),
      ),
    );
  }

  final Duration seatLockTtl;
  final int maxSeatsPerSession;
  final Duration paymentWindow;
}
