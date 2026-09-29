// Urządzenie push i zgoda na powiadomienia. Jedyne miejsce w aplikacji,
// które zna te adresy.
//
// Potwierdzone w kodzie serwera (Etap 8, bloki I i K — przeczytane, nie
// zgadnięte):
//   - PUT /account/devices — `{token, platform, replaces?}`; `platform` jedna
//     z `web|android|ios`, token od 32 do 1024 znaków ze zbioru `[A-Za-z0-9_:-]`.
//     Odpowiada 201 przy nowym urządzeniu i 200, gdy to samo zgłasza się
//     ponownie — dla aplikacji bez różnicy, bo żądanie jest idempotentne.
//     W odpowiedzi NIE MA tokenu (mógłby trafić do logów pośrednika), jest za to
//     `id` — ULID, którym urządzenie się wyrejestrowuje.
//   - DELETE /account/devices/{id} — 204 bez treści; cudze albo nieistniejące
//     urządzenie daje 404 bez rozróżnienia.
//   - PATCH /account/notifications — `{push_enabled?, screening_reminders?}`,
//     co najmniej jedno pole.
//
// `replaces` to poprzedni token TEJ instalacji. Serwer usuwa stary wiersz
// w tej samej transakcji, dzięki czemu nie powstaje drugie urządzenie dla tego
// samego telefonu — inaczej po każdym odświeżeniu tokenu przez FCM klient
// dostawałby to samo powiadomienie dwa razy, a potem trzy.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/json.dart';

/// Urządzenie zarejestrowane na serwerze — bez tokenu, bo serwer go nie oddaje.
class RegisteredDevice {
  const RegisteredDevice({required this.id, required this.platform});

  factory RegisteredDevice.fromJson(Map<String, Object?> json) =>
      RegisteredDevice(
        id: jsonString(json, 'id', 'device'),
        platform: jsonString(json, 'platform', 'device'),
      );

  /// ULID — do wyrejestrowania. Nie jest tajny, ale i nie jest tokenem.
  final String id;
  final String platform;
}

/// Ustawienia powiadomień KONTA — wspólne dla wszystkich urządzeń klienta.
class NotificationSettings {
  const NotificationSettings({
    required this.pushEnabled,
    required this.screeningReminders,
  });

  factory NotificationSettings.fromJson(Map<String, Object?> json) {
    const String where = 'notifications';
    return NotificationSettings(
      pushEnabled: jsonBool(json, 'push_enabled', where),
      screeningReminders: jsonBool(json, 'screening_reminders', where),
    );
  }

  /// Zgoda zapisana na serwerze. To NIE to samo co zgoda systemu na tym
  /// telefonie: pierwsza dotyczy konta, druga jednego urządzenia. Push dostaje
  /// urządzenie, które ma obie plus rejestrację (decyzja 352).
  final bool pushEnabled;
  final bool screeningReminders;
}

class DevicesRepository {
  const DevicesRepository(this._api);

  /// Aplikacja Flutter jest tu zawsze Androidem — iOS-a nie budujemy
  /// (Etap 9 obejmuje jedną platformę), a wysyłanie czegokolwiek innego
  /// kończyłoby się 422 z listą dozwolonych wartości.
  static const String platform = 'android';

  final ApiClient _api;

  /// Rejestracja albo odświeżenie. `replaces` podajemy TYLKO wtedy, gdy token
  /// naprawdę się zmienił — wysyłanie tej samej wartości w obu polach kazałoby
  /// serwerowi usunąć wiersz, który właśnie zakłada.
  Future<RegisteredDevice> register({
    required String token,
    String? replaces,
  }) async => RegisteredDevice.fromJson(
    await _api.putJson(
      '/account/devices',
      body: <String, Object?>{
        'token': token,
        'platform': platform,
        if (replaces != null && replaces != token) 'replaces': replaces,
      },
    ),
  );

  /// Wyrejestrowanie. `false` znaczy „serwer już go nie miał" i NIE jest
  /// błędem: wylogowanie na innym urządzeniu albo wygaśnięcie tokenu Sanctum
  /// kasuje wiersz samo (ON DELETE CASCADE), a użytkownik i tak chciał tylko,
  /// żeby przestało przychodzić.
  Future<bool> unregister(String id) async {
    try {
      await _api.deleteJson('/account/devices/$id');
      return true;
    } on ApiError catch (error) {
      // ENDPOINT_NOT_FOUND obok RESOURCE_NOT_FOUND, bo adres urządzenia ma
      // wzorzec (26 znaków ULID-a): zapis, który się w nim nie mieści, nie
      // trafia nawet do kontrolera. Dla nas znaczy to to samo — takiego
      // urządzenia na serwerze nie ma i nie będzie.
      if (error.code == ApiError.resourceNotFound ||
          error.code == ApiError.endpointNotFound) {
        return false;
      }
      rethrow;
    }
  }

  Future<NotificationSettings> settings() async =>
      NotificationSettings.fromJson(
        await _api.getJson('/account/notifications'),
      );

  /// Zgoda na koncie. Wysyłamy jedno pole — serwer przyjmuje dowolny podzbiór,
  /// a przesyłanie `screening_reminders` przy okazji nadpisywałoby ustawienie,
  /// którego użytkownik w tym momencie nie dotykał.
  Future<NotificationSettings> allowPush(bool value) async =>
      NotificationSettings.fromJson(
        await _api.patchJson(
          '/account/notifications',
          body: <String, Object?>{'push_enabled': value},
        ),
      );
}
