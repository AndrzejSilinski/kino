// Konfiguracja klienta z `GET /api/v1/client-config` (cache publiczny 60 s).
//
// Świadomie NIE czytamy `push.firebase`: to konfiguracja aplikacji WEB (klucz
// API przeglądarki, VAPID). Aplikacja na Androidzie bierze konfigurację
// Firebase z `google-services.json` wkompilowanego w APK (decyzja 292), więc
// gdybyśmy jej tu użyli, telefon rejestrowałby się jako urządzenie webowe.

import 'package:cinema/core/json.dart';

class ClientConfig {
  const ClientConfig({
    required this.apiVersion,
    required this.realtime,
    required this.booking,
    required this.pushEnabled,
  });

  factory ClientConfig.fromJson(Map<String, Object?> data) {
    return ClientConfig(
      apiVersion: jsonString(data, 'api_version', 'client-config'),
      realtime: RealtimeConfig.fromJson(
        jsonChild(data, 'realtime', 'client-config'),
      ),
      booking: BookingConfig.fromJson(
        jsonChild(data, 'booking', 'client-config'),
      ),
      pushEnabled: jsonBool(
        jsonChild(data, 'push', 'client-config'),
        'enabled',
        'client-config.push',
      ),
    );
  }

  final String apiVersion;
  final RealtimeConfig realtime;
  final BookingConfig booking;
  final bool pushEnabled;
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
