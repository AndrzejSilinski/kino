// Bezpieczny magazyn: token Sanctum i identyfikator sesji zakupowej.
//
// Token jest ważny 30 dni i daje dostęp do rezerwacji oraz biletów, więc nie
// może leżeć w SharedPreferences — to zwykły plik XML w katalogu aplikacji.
// Trzymamy go w Keystore (decyzja 266), a w manifeście mamy allowBackup=false,
// żeby nie wyjechał z telefonu w kopii zapasowej Google.
//
// Magazyn potrafi zawieść (uszkodzony Keystore po aktualizacji systemu,
// urządzenia z egzotycznym ROM-em), a to nie może wywrócić startu aplikacji:
// każdy odczyt i zapis ma try/catch, a błąd oznacza po prostu brak wartości.

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Wąski interfejs, żeby testy nie potrzebowały wtyczki natywnej.
abstract interface class SecureStore {
  Future<String?> read(String key);

  Future<void> write(String key, String value);

  Future<void> delete(String key);
}

/// Klucze magazynu — jedno miejsce, żeby literówka nie zgubiła tokenu.
class StoreKeys {
  const StoreKeys._();

  static const String token = 'auth_token';
  static const String bookingSession = 'booking_session_id';
}

class KeystoreSecureStore implements SecureStore {
  const KeystoreSecureStore([this._storage = const FlutterSecureStorage()]);

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) async {
    try {
      return await _storage.read(key: key);
    } on Exception {
      return null;
    }
  }

  @override
  Future<void> write(String key, String value) async {
    try {
      await _storage.write(key: key, value: value);
    } on Exception {
      // Brak zapisu oznacza tylko tyle, że po restarcie trzeba zalogować się
      // ponownie. To gorsze wrażenie, ale nie błąd, który warto pokazywać.
    }
  }

  @override
  Future<void> delete(String key) async {
    try {
      await _storage.delete(key: key);
    } on Exception {
      // Jak wyżej: przy wylogowaniu i tak czyścimy stan w pamięci.
    }
  }
}

/// Magazyn w pamięci — testy oraz awaryjne działanie, gdy Keystore odmawia.
class InMemorySecureStore implements SecureStore {
  final Map<String, String> _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<void> delete(String key) async => _values.remove(key);
}
