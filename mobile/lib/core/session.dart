// Sesja: token bearer i identyfikator sesji zakupowej.
//
// Klient API nie zna magazynu ani logiki logowania — pyta o te dwie wartości
// przez ten interfejs i oddaje mu identyfikator sesji, który serwer przysłał
// w nagłówku odpowiedzi.
//
// Sesja zakupowa jest JEDNA na instalację (decyzja 267). W SPA jest jedna na
// kartę przeglądarki, bo tam użytkownik może mieć dwa koszyki obok siebie;
// na telefonie taki przypadek nie istnieje, a stały identyfikator pozwala
// wrócić do porzuconego koszyka po zamknięciu aplikacji.

import 'dart:async';

import 'package:cinema/core/secure_store.dart';

/// To, czego klient API potrzebuje od sesji.
abstract interface class ApiSession {
  /// Token bearer albo null, gdy nikt nie jest zalogowany.
  String? get token;

  /// Identyfikator sesji zakupowej albo null, gdy serwer go jeszcze nie wydał.
  String? get bookingSessionId;

  /// Identyfikator z nagłówka odpowiedzi — zapamiętujemy każdy nowy.
  void rememberBookingSessionId(String value);

  /// Serwer odrzucił token (401 UNAUTHENTICATED): czyścimy go u siebie.
  void onTokenRejected();
}

class AppSession implements ApiSession {
  AppSession(this._store);

  final SecureStore _store;

  String? _token;
  String? _bookingSessionId;

  /// Wołane, gdy token przestał być ważny — ustawia je warstwa logowania,
  /// żeby przestawić ekran na stan wylogowany.
  void Function()? onRejected;

  @override
  String? get token => _token;

  @override
  String? get bookingSessionId => _bookingSessionId;

  /// Odczyt z magazynu przy starcie aplikacji.
  Future<void> restore() async {
    _token = await _store.read(StoreKeys.token);
    _bookingSessionId = await _store.read(StoreKeys.bookingSession);
  }

  Future<void> setToken(String token) async {
    _token = token;
    await _store.write(StoreKeys.token, token);
  }

  /// Wylogowanie: token znika z pamięci i z magazynu. Sesja zakupowa zostaje,
  /// bo blokady miejsc należą do niej, a nie do konta.
  Future<void> clearToken() async {
    _token = null;
    await _store.delete(StoreKeys.token);
  }

  @override
  void rememberBookingSessionId(String value) {
    if (value == _bookingSessionId) {
      return;
    }
    _bookingSessionId = value;
    // Zapis w tle: nagłówek przychodzi w środku obsługi odpowiedzi, a czekanie
    // na Keystore opóźniałoby pokazanie planu sali.
    unawaited(_store.write(StoreKeys.bookingSession, value));
  }

  @override
  void onTokenRejected() {
    _token = null;
    unawaited(_store.delete(StoreKeys.token));
    onRejected?.call();
  }
}
