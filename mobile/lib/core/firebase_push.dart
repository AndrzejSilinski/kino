// Prawdziwa warstwa powiadomień: Firebase Cloud Messaging i kanał Androida.
//
// To JEDYNY plik w aplikacji, który importuje `firebase_core`,
// `firebase_messaging` i `flutter_local_notifications` — tak samo jak
// `payment_sheet.dart` jest jedynym, który zna Stripe'a. Wszystko powyżej
// rozmawia z interfejsem `PushService` (decyzja 349), więc żaden test ani
// żaden ekran nie zależy od tego, czy telefon ma Usługi Google.
//
// Decyzja 358: NIE rejestrujemy procedury obsługi wiadomości w tle
// (`onBackgroundMessage`). To nie przeoczenie — to wniosek z kontraktu serwera.
// `FcmPushSender` zawsze wysyła sekcję `notification` (tytuł i treść), a taką
// wiadomość Android POKAZUJE SAM, gdy aplikacja jest w tle albo zamknięta;
// kliknięcie wraca do nas przez `onMessageOpenedApp` albo `getInitialMessage`.
// Procedura w tle jest potrzebna dopiero dla wiadomości SAMYCH DANYCH, których
// nasz serwer nie wysyła — a kosztuje uruchomienie osobnej izolacji Darta przy
// każdym powiadomieniu. Gdyby kiedyś doszła wiadomość cicha, trzeba dopisać
// funkcję najwyższego poziomu z `@pragma('vm:entry-point')`, która sama woła
// `Firebase.initializeApp()`, bo izolacja w tle startuje pusta.
//
// Decyzja 359: kanał powiadomień tworzymy przy KAŻDYM starcie aplikacji.
// Sprawdzone w źródle SDK Firebase (`CommonNotificationBuilder`): wpis
// `default_notification_channel_id` w manifeście jest używany TYLKO wtedy, gdy
// kanał o tym identyfikatorze już istnieje; w przeciwnym razie SDK po cichu
// zakłada własny kanał `fcm_fallback_notification_channel` o nazwie „Misc"
// i wypisuje ostrzeżenie w logu, którego nikt nie czyta. Tworzenie kanału jest
// idempotentne (drugie wywołanie nic nie zmienia), więc najtańszą gwarancją
// jest robić to zawsze przy starcie.

import 'dart:async';

import 'package:cinema/core/push.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Ikona powiadomienia — biała sylwetka w `res/drawable` (decyzja 360).
const String _icon = 'ic_notification';

/// Włączenie powiadomień przy starcie aplikacji.
///
/// Zwraca `NoPushService`, gdy Firebase nie daje się zainicjować — a to jest
/// NORMALNY stan, nie awaria: APK zbudowany bez `google-services.json` (bo tego
/// pliku nie ma w repozytorium, decyzja 292) rzuca wtedy `FirebaseException`
/// z kodem `core/not-initialized`, sprawdzone w źródle `firebase_core` 4.15.0.
/// Aplikacja ma się wtedy uruchomić i działać bez powiadomień, a nie zatrzymać
/// na białym ekranie — dokładnie tak samo jak na telefonie bez Usług Google.
Future<PushService> startPush() async {
  try {
    await Firebase.initializeApp();
  } on Exception {
    return const NoPushService();
  }
  return FirebasePushService();
}

class FirebasePushService implements PushService {
  FirebasePushService();

  final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin _local =
      FlutterLocalNotificationsPlugin();

  /// Kliknięcia z OBU źródeł w jednym strumieniu: powiadomienie pokazane przez
  /// system (aplikacja w tle) i powiadomienie pokazane przez nas (pierwszy
  /// plan). Dla reszty aplikacji to ta sama rzecz — użytkownik kliknął.
  final StreamController<PushNotification> _opened =
      StreamController<PushNotification>.broadcast();

  /// Kolejne numery powiadomień; ten sam numer zastąpiłby poprzednie.
  int _nextId = 0;

  @override
  Future<bool> available() async => Firebase.apps.isNotEmpty;

  @override
  Future<PushPermission> permission() async =>
      _permissionOf(await _messaging.getNotificationSettings());

  @override
  Future<PushPermission> requestPermission() async =>
      _permissionOf(await _messaging.requestPermission());

  @override
  Future<String?> token() async {
    try {
      return await _messaging.getToken();
    } on Exception {
      // Brak sieci albo niedostępne Usługi Google — stan „nie udało się
      // przygotować urządzenia", z którym ekran konta umie sobie poradzić.
      return null;
    }
  }

  @override
  Stream<String> get tokenRefreshes => _messaging.onTokenRefresh;

  @override
  Future<void> deleteToken() => _messaging.deleteToken();

  @override
  Future<void> prepare() async {
    await _local.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings(_icon),
      ),
      onDidReceiveNotificationResponse: _onLocalTap,
    );
    await _local
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >()
        ?.createNotificationChannel(
          const AndroidNotificationChannel(
            PushChannel.id,
            PushChannel.name,
            description: PushChannel.description,
            importance: Importance.high,
          ),
        );
    unawaited(
      FirebaseMessaging.onMessageOpenedApp.forEach(
        (RemoteMessage message) => _opened.add(_convert(message)),
      ),
    );
  }

  @override
  Stream<PushNotification> get foreground =>
      FirebaseMessaging.onMessage.map(_convert);

  @override
  Stream<PushNotification> get opened => _opened.stream;

  @override
  Future<PushNotification?> launchNotification() async {
    final RemoteMessage? message = await _messaging.getInitialMessage();
    return message == null ? null : _convert(message);
  }

  @override
  Future<void> display(PushNotification notification) => _local.show(
    id: _nextId++,
    title: notification.title,
    body: notification.body,
    notificationDetails: const NotificationDetails(
      android: AndroidNotificationDetails(
        PushChannel.id,
        PushChannel.name,
        channelDescription: PushChannel.description,
        icon: _icon,
        // Ważność kanału rozstrzyga od Androida 8, a `priority` niżej —
        // aplikacja działa od Androida 7, więc potrzebne są obie.
        importance: Importance.high,
        priority: Priority.high,
      ),
    ),
    // Ścieżka wraca do nas przy kliknięciu; sprawdzi ją `PushNotification`.
    payload: notification.path,
  );

  @override
  Future<PushProject?> project() async {
    if (Firebase.apps.isEmpty) {
      return null;
    }
    final FirebaseOptions options = Firebase.app().options;
    return PushProject(projectId: options.projectId, appId: options.appId);
  }

  /// Kliknięcie w powiadomienie, które pokazaliśmy sami.
  void _onLocalTap(NotificationResponse response) {
    final String? payload = response.payload;
    if (payload == null) {
      return;
    }
    _opened.add(PushNotification.fromData(<String, Object?>{'url': payload}));
  }

  /// Wiadomość FCM na nasz własny kształt (decyzja 350).
  ///
  /// `message.data` jest mapą wartości `dynamic` prosto z sieci, więc kopiujemy
  /// ją do mapy o znanym typie zamiast czytać po kolei z rzutowaniami.
  PushNotification _convert(RemoteMessage message) {
    final RemoteNotification? shown = message.notification;
    return PushNotification.fromData(
      Map<String, Object?>.from(message.data),
      title: shown?.title,
      body: shown?.body,
    );
  }

  /// Pięć stanów zgody systemu na cztery nasze.
  ///
  /// `provisional` (zgoda tymczasowa, iOS) traktujemy jak udzieloną: wiadomości
  /// dochodzą. `deniedPermanently` MUSI zostać osobno — po nim okna systemowego
  /// już nie będzie i ekran ma o tym powiedzieć wprost (decyzja 356).
  PushPermission _permissionOf(NotificationSettings settings) =>
      switch (settings.authorizationStatus) {
        AuthorizationStatus.authorized ||
        AuthorizationStatus.provisional => PushPermission.granted,
        AuthorizationStatus.denied => PushPermission.denied,
        AuthorizationStatus.deniedPermanently =>
          PushPermission.permanentlyDenied,
        AuthorizationStatus.notDetermined => PushPermission.notDetermined,
      };
}
