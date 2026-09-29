// Powiadomienia push NA TYM TELEFONIE.
//
// Decyzja 352: trzy różne rzeczy, których nie wolno mylić — dokładnie te same
// trzy co w SPA (Etap 8, blok L), bo to ten sam system, tylko inny klient:
//   1. zgoda na koncie (`push_enabled`) — wspólna dla wszystkich urządzeń,
//      zmiana na telefonie dotyczy też przeglądarki,
//   2. zgoda systemu na TYM urządzeniu — zna ją tylko ten telefon,
//   3. urządzenie zarejestrowane na serwerze (token FCM).
// Powiadomienie dostaje urządzenie, które ma wszystkie trzy. Dlatego „Włącz
// powiadomienia" ustawia je razem, a „Wyłącz" zdejmuje TYLKO trzecią: zgody
// na koncie nie ruszamy, bo należy również do pozostałych urządzeń klienta,
// a zgody systemowej i tak nie da się cofnąć z kodu.
//
// Decyzja 353: zapis `{id, token}` trzymamy w Keystore, obok tokenu logowania.
// `id` jest potrzebne do wyrejestrowania (serwer nie oddaje tokenu w żadnej
// odpowiedzi — i słusznie), a zapamiętany `token` jest jedynym sposobem, żeby
// rozpoznać, że FCM wydał nowy. Bez tego porównania każde odświeżenie zakładało
// nowy wiersz i klient dostawałby to samo powiadomienie dwa razy, potem trzy.
//
// Decyzja 354: o dostępności push rozstrzyga `push.enabled` z konfiguracji
// serwera, a NIE obecność bloku `push.android`. To rozróżnienie z bloku K:
// `enabled` mówi, czy serwer ma czym wysyłać, a blok `android` to tylko dane
// projektu Firebase, których używamy w diagnostyce do wykrycia, że aplikację
// zbudowano z `google-services.json` z INNEGO projektu niż ten, którym serwer
// wysyła. Gdyby brak tego bloku wyłączał push, wdrożenie, które po prostu nie
// wypełniło dwóch zmiennych opisowych, przestałoby dostawać powiadomienia bez
// żadnego powodu.

import 'dart:async';
import 'dart:convert';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/data/devices_repository.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

enum PushStatus {
  /// Trwa sprawdzanie — stan startowy, zanim poznamy odpowiedzi.
  checking,

  /// Serwer nie wysyła powiadomień (kanał wyłączony albo nieskonfigurowany).
  unavailable,

  /// To urządzenie ich nie obsłuży (telefon bez Usług Google).
  unsupported,

  /// System odmówił NA STAŁE — pomoże już tylko zmiana w ustawieniach telefonu.
  denied,

  /// Można włączyć.
  off,

  /// Włączone: jest zgoda, jest token, urządzenie jest zarejestrowane.
  on,
}

class PushState {
  const PushState({required this.status, this.busy = false, this.message});

  final PushStatus status;

  /// Trwa żądanie — przycisk jest wtedy zablokowany.
  final bool busy;

  /// Komunikat do pokazania obok przełącznika (błąd albo wyjaśnienie).
  final String? message;

  bool get isOn => status == PushStatus.on;

  @override
  String toString() =>
      'PushState(${status.name}${busy ? ', trwa żądanie' : ''})';
}

/// Zapamiętane urządzenie: `id` do wyrejestrowania, `token` do porównania.
class StoredPushDevice {
  const StoredPushDevice({required this.id, required this.token});

  final String id;
  final String token;
}

class PushController extends Notifier<PushState> {
  @override
  PushState build() {
    // FCM potrafi wydać nowy token w środku pracy aplikacji. Subskrypcja żyje
    // tak długo jak provider — bez niej stary token zostałby na serwerze,
    // a powiadomienia po cichu przestałyby dochodzić.
    final StreamSubscription<String> refreshes = ref
        .watch(pushServiceProvider)
        .tokenRefreshes
        .listen(_onTokenRefresh);
    ref.onDispose(refreshes.cancel);
    return const PushState(status: PushStatus.checking);
  }

  PushService get _service => ref.read(pushServiceProvider);

  DevicesRepository get _devices => ref.read(devicesRepositoryProvider);

  SecureStore get _store => ref.read(secureStoreProvider);

  /// Stan dla tego telefonu. Przy włączonych powiadomieniach odświeża po cichu
  /// token — FCM mógł wydać nowy, gdy aplikacja nie działała.
  Future<void> check() async {
    state = const PushState(status: PushStatus.checking);
    if (!await _serverSends()) {
      state = const PushState(status: PushStatus.unavailable);
      return;
    }
    if (!await _service.available()) {
      state = const PushState(status: PushStatus.unsupported);
      return;
    }
    final PushPermission permission = await _service.permission();
    if (permission == PushPermission.permanentlyDenied) {
      state = const PushState(status: PushStatus.denied);
      return;
    }
    final StoredPushDevice? device = await _stored();
    if (permission != PushPermission.granted || device == null) {
      // Zgoda bez rejestracji zdarza się po wylogowaniu: serwer skasował
      // urządzenie, uprawnienie systemu zostało. Przycisk „Włącz" zadziała
      // wtedy bez pytania o zgodę.
      state = const PushState(status: PushStatus.off);
      return;
    }
    state = const PushState(status: PushStatus.on);
    await _refreshToken(device);
  }

  /// Włączenie na tym telefonie: zgoda systemu, zgoda na koncie, rejestracja.
  ///
  /// Kolejność nie jest dowolna. Najpierw pytamy system, bo to jedyny krok,
  /// który może odmówić bez naszej winy — i nie ma sensu zapisywać zgody na
  /// koncie, skoro i tak nic nie dotrze. Zgoda na koncie idzie przed
  /// rejestracją, bo urządzenie zapisane przy wyłączonej zgodzie byłoby
  /// wierszem, do którego serwer i tak nic nie wyśle.
  Future<bool> enable() async {
    if (state.busy) {
      return false;
    }
    final PushStatus previous = state.status;
    state = PushState(status: previous, busy: true);
    try {
      PushPermission permission = await _service.permission();
      if (permission != PushPermission.granted) {
        permission = await _service.requestPermission();
      }
      if (permission != PushPermission.granted) {
        final bool forGood = permission == PushPermission.permanentlyDenied;
        state = PushState(
          status: forGood ? PushStatus.denied : PushStatus.off,
          // Przy odmowie na stałe komunikat byłby powtórzeniem: ekran pokazuje
          // wtedy stałe wyjaśnienie z drogą przez ustawienia telefonu.
          message: forGood
              ? null
              : 'Bez zgody systemu powiadomienia nie dotrą. '
                    'Możesz jej udzielić przy kolejnej próbie.',
        );
        return false;
      }
      final String? token = await _service.token();
      if (token == null) {
        state = const PushState(
          status: PushStatus.off,
          message:
              'Nie udało się przygotować tego urządzenia do powiadomień. '
              'Spróbuj ponownie.',
        );
        return false;
      }
      await _devices.allowPush(true);
      await _register(token);
      state = const PushState(status: PushStatus.on);
      return true;
    } on ApiError catch (error) {
      // Komunikat serwera jest po polsku i gotowy do pokazania (decyzja 20).
      state = PushState(status: previous, message: error.message);
      return false;
    } finally {
      // Nieprzewidziany błąd nie może zostawić zablokowanego przycisku.
      if (state.busy) {
        state = PushState(status: previous);
      }
    }
  }

  /// Wyłączenie NA TYM TELEFONIE. Zgoda na koncie zostaje (decyzja 352).
  Future<void> disable() async {
    if (state.busy) {
      return;
    }
    final PushStatus previous = state.status;
    state = PushState(status: previous, busy: true);
    try {
      final StoredPushDevice? device = await _stored();
      if (device != null) {
        // `false` znaczy „serwer już go nie miał" — dla nas tyle samo co „usunięto".
        await _devices.unregister(device.id);
      }
      await _forgetLocally();
      state = const PushState(status: PushStatus.off);
    } on ApiError catch (error) {
      state = PushState(status: previous, message: error.message);
    } finally {
      if (state.busy) {
        state = PushState(status: previous);
      }
    }
  }

  /// Wylogowanie: serwer usunął urządzenie sam (token Sanctum znika razem
  /// z wierszem), więc zostaje posprzątanie u siebie. Bez tego zapis w Keystore
  /// przeżyłby wylogowanie i następny użytkownik tego telefonu zobaczyłby
  /// „powiadomienia włączone" dla urządzenia, którego na serwerze nie ma.
  Future<void> forget() async {
    if (await _stored() == null) {
      return;
    }
    await _forgetLocally();
    state = PushState(
      status: state.status == PushStatus.on ? PushStatus.off : state.status,
    );
  }

  /// Start aplikacji: pełne sprawdzenie tylko tam, gdzie push już włączono.
  /// Pozostali nie płacą za nie ani jednym żądaniem.
  Future<void> refreshIfRegistered() async {
    if (await _stored() != null) {
      await check();
    }
  }

  /// Schowanie komunikatu (krzyżyk obok tekstu).
  void dismiss() {
    state = PushState(status: state.status, busy: state.busy);
  }

  /// Czy serwer w ogóle wysyła powiadomienia (decyzja 354).
  Future<bool> _serverSends() async {
    try {
      final ClientConfig config = await ref.read(clientConfigProvider.future);
      return config.pushEnabled;
    } on ApiError {
      // Bez konfiguracji nie wiemy nic — lepiej pokazać „niedostępne" niż
      // przełącznik, który za chwilę i tak nie zadziała.
      return false;
    }
  }

  Future<void> _register(String token) async {
    final StoredPushDevice? previous = await _stored();
    final RegisteredDevice device = await _devices.register(
      token: token,
      replaces: previous?.token,
    );
    await _store.write(
      StoreKeys.pushDevice,
      jsonEncode(<String, String>{'id': device.id, 'token': token}),
    );
  }

  Future<void> _refreshToken(StoredPushDevice device) async {
    try {
      final String? token = await _service.token();
      if (token != null && token != device.token) {
        await _register(token);
      }
    } on ApiError {
      // Odświeżenie przy wejściu na ekran nie może psuć widoku — spróbujemy
      // przy następnym starcie. Token na serwerze jest wtedy stary, ale to
      // najwyżej jedno niedostarczone powiadomienie, a nie zepsuty ekran.
    }
  }

  void _onTokenRefresh(String token) {
    unawaited(_reregister(token));
  }

  Future<void> _reregister(String token) async {
    // Nowy token obchodzi nas tylko wtedy, gdy to urządzenie jest zapisane;
    // inaczej rejestrowalibyśmy telefon, który o powiadomienia nie prosił.
    if (await _stored() == null) {
      return;
    }
    try {
      await _register(token);
      state = const PushState(status: PushStatus.on);
    } on ApiError {
      // Jak wyżej: następny start spróbuje jeszcze raz.
    }
  }

  Future<StoredPushDevice?> _stored() async {
    final String? raw = await _store.read(StoreKeys.pushDevice);
    if (raw == null) {
      return null;
    }
    try {
      final Object? decoded = jsonDecode(raw);
      if (decoded is Map<String, Object?>) {
        final Object? id = decoded['id'];
        final Object? token = decoded['token'];
        if (id is String && token is String) {
          return StoredPushDevice(id: id, token: token);
        }
      }
    } on FormatException {
      // Zapis z innej wersji aplikacji albo uszkodzony — traktujemy jak brak.
    }
    return null;
  }

  Future<void> _forgetLocally() async {
    await _store.delete(StoreKeys.pushDevice);
    try {
      // Token usuwamy też po stronie FCM: inaczej wiadomości nadal trafiałyby
      // na to urządzenie, tyle że nie miałby ich kto przyjąć.
      await _service.deleteToken();
    } on Exception {
      // Warstwa natywna bywa niedostępna (brak sieci, wyłączone Usługi Google).
      // Zapis lokalny już zniknął, a to on decyduje o tym, co widzi użytkownik.
    }
  }
}
