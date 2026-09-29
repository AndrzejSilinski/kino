// Atrapa warstwy powiadomień (decyzja 349).
//
// Dzięki niej test przechodzi całą drogę „zgoda → token → rejestracja →
// odświeżenie tokenu → wyłączenie" bez Firebase, bez Usług Google i bez
// telefonu. Odtwarza też stany, których na sprzęcie prawie nie da się wywołać
// na żądanie: odmowę na stałe, brak tokenu i token wymieniony w środku sesji.

import 'dart:async';

import 'package:cinema/core/push.dart';

class FakePushService implements PushService {
  FakePushService({
    this.supported = true,
    this.systemPermission = PushPermission.notDetermined,
    this.afterRequest = PushPermission.granted,
    this.tokenValue = 'tokenFcmTestowy',
  });

  /// Czy to „urządzenie" obsługuje powiadomienia.
  final bool supported;

  /// Zgoda systemu. Pole jawne, a nie prywatne z parametrem nazwanym:
  /// `prefer_initializing_formals` przy `--fatal-infos` zatrzymuje analizę,
  /// a podpowiedzi tej reguły (`this._permission`) nie da się użyć, bo nazwany
  /// parametr nie może zaczynać się od podkreślenia (pułapka DZ).
  PushPermission systemPermission;

  /// Czym kończy się pytanie o zgodę.
  final PushPermission afterRequest;

  /// Token FCM; `null` odtwarza sytuację, w której nie udało się go pobrać.
  String? tokenValue;

  int requests = 0;
  int tokenCalls = 0;
  int deletions = 0;
  int prepared = 0;

  final List<PushNotification> displayed = <PushNotification>[];

  /// Powiadomienie, którym „uruchomiono" aplikację — do odczytu raz.
  PushNotification? launch;

  /// Projekt Firebase „wkompilowany w APK"; `null` odtwarza build bez
  /// `google-services.json`.
  PushProject? projectValue;

  final StreamController<String> _tokens = StreamController<String>.broadcast();
  final StreamController<PushNotification> _foreground =
      StreamController<PushNotification>.broadcast();
  final StreamController<PushNotification> _opened =
      StreamController<PushNotification>.broadcast();

  /// FCM wydał nowy token w trakcie pracy aplikacji.
  void emitToken(String token) {
    tokenValue = token;
    _tokens.add(token);
  }

  void emitForeground(PushNotification notification) =>
      _foreground.add(notification);

  void emitOpened(PushNotification notification) => _opened.add(notification);

  Future<void> dispose() async {
    await _tokens.close();
    await _foreground.close();
    await _opened.close();
  }

  @override
  Future<bool> available() async => supported;

  @override
  Future<PushPermission> permission() async => systemPermission;

  @override
  Future<PushPermission> requestPermission() async {
    requests++;
    systemPermission = afterRequest;
    return systemPermission;
  }

  @override
  Future<String?> token() async {
    tokenCalls++;
    return tokenValue;
  }

  @override
  Stream<String> get tokenRefreshes => _tokens.stream;

  @override
  Future<void> deleteToken() async {
    deletions++;
    tokenValue = null;
  }

  @override
  Future<void> prepare() async {
    prepared++;
  }

  @override
  Stream<PushNotification> get foreground => _foreground.stream;

  @override
  Stream<PushNotification> get opened => _opened.stream;

  @override
  Future<PushNotification?> launchNotification() async {
    final PushNotification? once = launch;
    launch = null;
    return once;
  }

  @override
  Future<void> display(PushNotification notification) async {
    displayed.add(notification);
  }

  @override
  Future<PushProject?> project() async => projectValue;
}
