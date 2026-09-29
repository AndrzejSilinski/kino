// Powiadomienia push za jedną ścianą — jak Stripe (blok H), udostępnianie
// (blok I) i aparat (blok J).
//
// Decyzja 349: `firebase_messaging` i `flutter_local_notifications` rozmawiają
// z kodem natywnym, więc w `flutter test` ich nie ma — a cała logika, która
// decyduje O CZYMKOLWIEK (kiedy pytać o zgodę, kiedy rejestrować urządzenie,
// co zrobić z nowym tokenem, gdzie zaprowadzić po kliknięciu), jest właśnie
// logiką, nie warstwą natywną. Za tym interfejsem daje się ją przejść w całości
// w testach, razem ze ścieżkami, których na telefonie nie zobaczyłbym prawie
// nigdy: odmową zgody na stałe, tokenem odświeżonym w środku sesji, urządzeniem
// usuniętym na serwerze.
//
// Decyzja 350: powiadomienie z FCM sprowadzamy do WŁASNEGO kształtu przy samym
// wejściu. `RemoteMessage.data` to mapa wartości `dynamic` pochodzących z sieci;
// gdyby wędrowała przez aplikację w tej postaci, każdy odczyt byłby cichym
// rzutowaniem (analizator ma `strict-casts`, ale mapa `dynamic` obchodzi go
// legalnie). Tu jeden konstruktor sprawdza wszystko raz, a reszta aplikacji
// widzi już tylko pola o znanych typach.

/// Zgoda systemu na pokazywanie powiadomień.
///
/// Cztery stany, bo dwa ostatnie znaczą coś zupełnie innego: po `denied`
/// wolno zapytać jeszcze raz (Android pokazuje okno po raz drugi), po
/// `permanentlyDenied` już nie — tam jedyną drogą są ustawienia systemu.
/// Mylenie ich kończy się przyciskiem „Włącz", który nic nie robi.
enum PushPermission { granted, denied, permanentlyDenied, notDetermined }

/// Kanał powiadomień na Androidzie 8+.
///
/// Kanał to nie ozdoba: bez niego system pokazuje powiadomienie z domyślną,
/// najcichszą ważnością, a użytkownik nie ma czego wyciszyć osobno. Nazwa
/// i opis są widoczne w ustawieniach aplikacji, więc po polsku.
class PushChannel {
  const PushChannel._();

  static const String id = 'cinema_bookings';
  static const String name = 'Rezerwacje i seanse';
  static const String description =
      'Potwierdzenia płatności, przypomnienia o seansie i odwołane seanse.';
}

/// Powiadomienie sprowadzone do postaci, którą rozumie aplikacja.
///
/// `title` i `body` bywają puste: wiadomość wysłana wyłącznie z sekcją `data`
/// (tak wygląda powiadomienie ciche) nie ma czego pokazać. `type` pochodzi
/// z `data.type` serwera: `booking.paid`, `screening.reminder`,
/// `booking.cancelled`.
class PushNotification {
  const PushNotification({
    required this.type,
    this.title,
    this.body,
    this.path,
  });

  /// Złożenie z tego, co przysyła FCM. Nic tu nie rzuca wyjątkiem — odrzucona
  /// wartość zamienia się w `null`, bo powiadomienie o nieznanym kształcie ma
  /// się najwyżej nie otworzyć, a nie wywrócić aplikację przy starcie.
  factory PushNotification.fromData(
    Map<String, Object?> data, {
    String? title,
    String? body,
  }) => PushNotification(
    type: _text(data['type']) ?? unknownType,
    title: _text(title),
    body: _text(body),
    path: appPath(data['url']),
  );

  static const String unknownType = 'unknown';

  final String type;
  final String? title;
  final String? body;

  /// Ścieżka do otwarcia w aplikacji albo `null`, gdy adres nie przeszedł
  /// sprawdzenia.
  final String? path;

  /// Czy jest co pokazać na ekranie, gdy aplikacja jest na pierwszym planie.
  bool get hasText => title != null || body != null;

  /// Ścieżka z powiadomienia — albo `null`.
  ///
  /// Decyzja 351: adres z powiadomienia traktujemy jak dane z sieci, bo nim
  /// jest. Wiadomość FCM przychodzi spoza aplikacji i nikt po drodze nie
  /// gwarantuje, że `data.url` napisał nasz serwer — w sekcji `data` można
  /// wysłać cokolwiek, mając sam token urządzenia. Dlatego przyjmujemy
  /// WYŁĄCZNIE ścieżkę wewnątrz aplikacji: musi zaczynać się od jednego
  /// ukośnika (`//host` to adres z innego serwera), nie może mieć schematu
  /// (`intent:`, `javascript:`, `https:`), znaków spoza wąskiego zbioru ani
  /// `..`. Bez tego kliknięcie w powiadomienie byłoby wejściem, przez które
  /// da się wysłać użytkownika, gdzie się chce. Czy pod ścieżką coś jest,
  /// rozstrzyga router (wzorce `Routes`) — tu pilnujemy tylko KSZTAŁTU.
  static String? appPath(Object? raw) {
    if (raw is! String) {
      return null;
    }
    final String url = raw.trim();
    if (url.isEmpty || url.length > 200) {
      return null;
    }
    if (!url.startsWith('/') || url.startsWith('//') || url.contains('..')) {
      return null;
    }
    return _path.hasMatch(url) ? url : null;
  }

  static final RegExp _path = RegExp(r'^/[A-Za-z0-9/\-_]*$');

  static String? _text(Object? value) =>
      value is String && value.trim().isNotEmpty ? value.trim() : null;

  @override
  String toString() => 'PushNotification($type, path: $path)';
}

/// Wszystko, czego stan powiadomień potrzebuje od warstwy natywnej.
abstract interface class PushService {
  /// Czy to urządzenie w ogóle odbierze push (usługi Google, zainicjowany
  /// Firebase). Na telefonie bez Usług Google odpowiedź brzmi „nie" i to nie
  /// jest awaria — tak ma po prostu wtedy wyglądać ekran.
  Future<bool> available();

  /// Zgoda systemu BEZ pytania użytkownika.
  Future<PushPermission> permission();

  /// Zgoda systemu Z pytaniem (okno systemowe).
  Future<PushPermission> requestPermission();

  /// Token FCM tej instalacji albo `null`, gdy nie udało się go pobrać.
  Future<String?> token();

  /// FCM potrafi wydać nowy token w środku pracy aplikacji (kopia zapasowa,
  /// czyszczenie danych, aktualizacja). Wtedy stary przestaje działać.
  Stream<String> get tokenRefreshes;

  Future<void> deleteToken();

  /// Kanał powiadomień i cokolwiek jeszcze trzeba przygotować raz przy starcie.
  Future<void> prepare();

  /// Powiadomienie, które przyszło przy aplikacji NA PIERWSZYM PLANIE.
  /// System go wtedy nie pokazuje — pokazujemy je sami.
  Stream<PushNotification> get foreground;

  /// Kliknięcie w powiadomienie, gdy aplikacja działała w tle.
  Stream<PushNotification> get opened;

  /// Powiadomienie, którym URUCHOMIONO aplikację (była zamknięta).
  /// Do odczytania RAZ — drugie wywołanie odda `null`.
  Future<PushNotification?> launchNotification();

  /// Pokazanie powiadomienia własnymi siłami (pierwszy plan).
  Future<void> display(PushNotification notification);
}

/// Serwis, który nic nie robi i mówi o tym wprost.
///
/// Stoi pod `pushServiceProvider`, dopóki nie wejdzie warstwa Firebase
/// (blok M2). Dzięki temu cały stan powiadomień, ekran i testy powstają
/// i działają wcześniej, a aplikacja zachowuje się jak na telefonie bez Usług
/// Google: „powiadomienia niedostępne na tym urządzeniu" zamiast awarii.
class NoPushService implements PushService {
  const NoPushService();

  @override
  Future<bool> available() async => false;

  @override
  Future<PushPermission> permission() async => PushPermission.notDetermined;

  @override
  Future<PushPermission> requestPermission() async => PushPermission.denied;

  @override
  Future<String?> token() async => null;

  @override
  Stream<String> get tokenRefreshes => Stream<String>.empty();

  @override
  Future<void> deleteToken() async {}

  @override
  Future<void> prepare() async {}

  @override
  Stream<PushNotification> get foreground => Stream<PushNotification>.empty();

  @override
  Stream<PushNotification> get opened => Stream<PushNotification>.empty();

  @override
  Future<PushNotification?> launchNotification() async => null;

  @override
  Future<void> display(PushNotification notification) async {}
}
