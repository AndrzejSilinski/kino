// Cykl życia powiadomień na tym telefonie: sprawdzenie, włączenie, odświeżenie
// tokenu, wyłączenie, wylogowanie.
//
// Wszystko bez Firebase i bez Usług Google (decyzja 349). Najważniejsze
// sprawdzenia dotyczą rzeczy, które psują push PO CICHU — nic się nie wywraca,
// nic nie miga na czerwono, powiadomienia po prostu przestają przychodzić:
// stary token zostawiony na serwerze, dwa wiersze dla jednego telefonu,
// zapis w Keystore, który przeżył wylogowanie.

import 'dart:convert';

import 'package:cinema/core/push.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/push.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fake_push_service.dart';
import '../fixtures/fixtures.dart';

const String deviceId = '01M2R0FF9TF8JNNQ9GBNZJ3TDQ';

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

/// Atrapa serwera: konfiguracja, urządzenia, ustawienia powiadomień.
class FakePushApi {
  FakePushApi({
    this.config = 'client_config_push_android',
    this.deviceStatus = 204,
  });

  /// Fikstura konfiguracji klienta.
  final String config;

  /// Czym serwer odpowiada na wyrejestrowanie (404 = już go nie miał).
  final int deviceStatus;

  final List<Map<String, Object?>> registrations = <Map<String, Object?>>[];
  final List<String> deletions = <String>[];
  int consentCalls = 0;
  int logouts = 0;

  http.Response handle(http.Request request) {
    final String path = request.url.path;
    if (path.endsWith('/client-config')) {
      return reply(envelope(config));
    }
    if (path.endsWith('/auth/logout')) {
      logouts++;
      return http.Response('', 204);
    }
    if (path.endsWith('/account/notifications')) {
      consentCalls++;
      return reply(<String, Object?>{
        'data': <String, Object?>{
          'push_enabled': true,
          'push_consent_at': '2026-09-17T10:00:00+00:00',
          'screening_reminders': true,
        },
      });
    }
    if (request.method == 'DELETE') {
      deletions.add(path.split('/').last);
      return deviceStatus == 204
          ? http.Response('', 204)
          : reply(<String, Object?>{
              'message': 'Nie znaleziono zasobu.',
              'code': 'RESOURCE_NOT_FOUND',
            }, deviceStatus);
    }
    registrations.add(jsonDecode(request.body) as Map<String, Object?>);
    return reply(<String, Object?>{
      'data': <String, Object?>{
        'id': deviceId,
        'platform': 'android',
        'last_seen_at': '2026-09-17T10:00:00+00:00',
        'created_at': '2026-09-17T10:00:00+00:00',
      },
    }, registrations.length == 1 ? 201 : 200);
  }
}

ProviderContainer containerFor(
  FakePushApi api,
  FakePushService service, {
  required SecureStore store,
}) {
  final AppSession session = AppSession(store);
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(store),
      sessionProvider.overrideWithValue(session),
      pushServiceProvider.overrideWithValue(service),
    ],
  );
  addTearDown(container.dispose);
  addTearDown(service.dispose);
  return container;
}

/// Zapis urządzenia w magazynie — tak jak zapisuje go stan.
Future<void> remember(
  SecureStore store, {
  String id = deviceId,
  required String token,
}) => store.write(
  StoreKeys.pushDevice,
  jsonEncode(<String, String>{'id': id, 'token': token}),
);

Future<Map<String, Object?>?> stored(SecureStore store) async {
  final String? raw = await store.read(StoreKeys.pushDevice);
  return raw == null ? null : jsonDecode(raw) as Map<String, Object?>;
}

void main() {
  test('serwer bez kanału push: przełącznika nie ma czym włączyć', () async {
    // Konfiguracja z `push.enabled = false` — kino, które nie skonfigurowało
    // wysyłki. Pokazujemy „niedostępne", a nie przycisk, który nic nie da.
    final FakePushApi api = FakePushApi(config: 'client_config_push_off');
    final ProviderContainer container = containerFor(
      api,
      FakePushService(),
      store: InMemorySecureStore(),
    );

    await container.read(pushProvider.notifier).check();

    expect(container.read(pushProvider).status, PushStatus.unavailable);
  });

  test('telefon bez obsługi powiadomień', () async {
    final ProviderContainer container = containerFor(
      FakePushApi(),
      FakePushService(supported: false),
      store: InMemorySecureStore(),
    );

    await container.read(pushProvider.notifier).check();

    expect(container.read(pushProvider).status, PushStatus.unsupported);
  });

  test('odmowa na stałe to inny stan niż „wyłączone"', () async {
    // Po zwykłej odmowie wolno zapytać jeszcze raz; po odmowie na stałe już
    // nie — jedyną drogą są ustawienia systemu. Ekran musi to rozróżniać,
    // inaczej pokazałby przycisk, który nic nie robi.
    final ProviderContainer container = containerFor(
      FakePushApi(),
      FakePushService(systemPermission: PushPermission.permanentlyDenied),
      store: InMemorySecureStore(),
    );

    await container.read(pushProvider.notifier).check();

    expect(container.read(pushProvider).status, PushStatus.denied);
  });

  test('zgoda bez rejestracji znaczy „wyłączone"', () async {
    // Tak wygląda telefon po wylogowaniu: serwer skasował urządzenie razem
    // z tokenem, uprawnienie systemu zostało.
    final ProviderContainer container = containerFor(
      FakePushApi(),
      FakePushService(systemPermission: PushPermission.granted),
      store: InMemorySecureStore(),
    );

    await container.read(pushProvider.notifier).check();

    expect(container.read(pushProvider).status, PushStatus.off);
  });

  test('włączenie: zgoda systemu, zgoda na koncie, rejestracja', () async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    final FakePushService service = FakePushService();
    final ProviderContainer container = containerFor(
      api,
      service,
      store: store,
    );

    final bool enabled = await container.read(pushProvider.notifier).enable();

    expect(enabled, isTrue);
    expect(container.read(pushProvider).status, PushStatus.on);
    expect(service.requests, 1);
    expect(
      api.consentCalls,
      1,
      reason: 'zgoda na koncie idzie przed rejestracją',
    );
    expect(api.registrations.single['platform'], 'android');
    expect(api.registrations.single['token'], 'tokenFcmTestowy');
    expect(api.registrations.single.containsKey('replaces'), isFalse);
    // Bez zapisu nie da się później ani wyrejestrować, ani rozpoznać nowego
    // tokenu (decyzja 353).
    expect(await stored(store), <String, Object?>{
      'id': deviceId,
      'token': 'tokenFcmTestowy',
    });
  });

  test('odmowa na stałe przy włączaniu nie rusza serwera', () async {
    final FakePushApi api = FakePushApi();
    final ProviderContainer container = containerFor(
      api,
      FakePushService(afterRequest: PushPermission.permanentlyDenied),
      store: InMemorySecureStore(),
    );

    expect(await container.read(pushProvider.notifier).enable(), isFalse);

    expect(container.read(pushProvider).status, PushStatus.denied);
    expect(api.consentCalls, 0);
    expect(api.registrations, isEmpty);
  });

  test('zwykła odmowa zostawia drogę powrotną', () async {
    final ProviderContainer container = containerFor(
      FakePushApi(),
      FakePushService(afterRequest: PushPermission.denied),
      store: InMemorySecureStore(),
    );

    expect(await container.read(pushProvider.notifier).enable(), isFalse);

    final PushState state = container.read(pushProvider);
    expect(state.status, PushStatus.off);
    expect(state.message, contains('zgody systemu'));
    expect(state.busy, isFalse);
  });

  test('brak tokenu FCM nie zapisuje urządzenia', () async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    final ProviderContainer container = containerFor(
      api,
      FakePushService(tokenValue: null),
      store: store,
    );

    expect(await container.read(pushProvider.notifier).enable(), isFalse);

    expect(container.read(pushProvider).status, PushStatus.off);
    expect(api.registrations, isEmpty);
    expect(await stored(store), isNull);
  });

  test('nowy token od FCM zastępuje stary jednym żądaniem', () async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    // Token w serwisie ten sam co zapamiętany: sprawdzenie stanu nie ma
    // wtedy czego odświeżać i w żądaniach zostaje wyłącznie to, co wywołała
    // wymiana tokenu.
    final FakePushService service = FakePushService(
      systemPermission: PushPermission.granted,
      tokenValue: 'tokenStary',
    );
    await remember(store, token: 'tokenStary');
    final ProviderContainer container = containerFor(
      api,
      service,
      store: store,
    );
    // Odczyt tworzy kontroler, a ten zapisuje się na strumień odświeżeń.
    await container.read(pushProvider.notifier).check();
    expect(api.registrations, isEmpty);

    service.emitToken('tokenNowy');
    await pumpEventQueue();

    expect(api.registrations.last['token'], 'tokenNowy');
    expect(api.registrations.last['replaces'], 'tokenStary');
    expect((await stored(store))!['token'], 'tokenNowy');
    expect(container.read(pushProvider).status, PushStatus.on);
  });

  test('nowy token na telefonie BEZ push nie rejestruje niczego', () async {
    final FakePushApi api = FakePushApi();
    final FakePushService service = FakePushService();
    final ProviderContainer container = containerFor(
      api,
      service,
      store: InMemorySecureStore(),
    );
    container.read(pushProvider.notifier);

    service.emitToken('tokenNowy');
    await pumpEventQueue();

    expect(api.registrations, isEmpty);
  });

  test('wejście na ekran odświeża token po cichu', () async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    await remember(store, token: 'tokenStary');
    final ProviderContainer container = containerFor(
      api,
      // FCM wydał nowy token, gdy aplikacja nie działała.
      FakePushService(
        systemPermission: PushPermission.granted,
        tokenValue: 'tokenPoPrzerwie',
      ),
      store: store,
    );

    await container.read(pushProvider.notifier).check();

    expect(container.read(pushProvider).status, PushStatus.on);
    expect(api.registrations.single['replaces'], 'tokenStary');
    expect((await stored(store))!['token'], 'tokenPoPrzerwie');
  });

  test('wyłączenie kasuje urządzenie na serwerze i zapis', () async {
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    final FakePushService service = FakePushService(
      systemPermission: PushPermission.granted,
    );
    await remember(store, token: 'tokenFcmTestowy');
    final ProviderContainer container = containerFor(
      api,
      service,
      store: store,
    );
    await container.read(pushProvider.notifier).check();

    await container.read(pushProvider.notifier).disable();

    expect(api.deletions, <String>[deviceId]);
    expect(await stored(store), isNull);
    expect(service.deletions, 1, reason: 'token znika też po stronie FCM');
    expect(container.read(pushProvider).status, PushStatus.off);
    // Zgoda na koncie ZOSTAJE: dotyczy też innych urządzeń (decyzja 352).
    expect(api.consentCalls, 0);
  });

  test('urządzenie, którego serwer już nie ma, też da się wyłączyć', () async {
    final FakePushApi api = FakePushApi(deviceStatus: 404);
    final InMemorySecureStore store = InMemorySecureStore();
    await remember(store, token: 'tokenFcmTestowy');
    final ProviderContainer container = containerFor(
      api,
      FakePushService(systemPermission: PushPermission.granted),
      store: store,
    );

    await container.read(pushProvider.notifier).disable();

    expect(container.read(pushProvider).status, PushStatus.off);
    expect(await stored(store), isNull);
  });

  test('wylogowanie zapomina urządzenie push', () async {
    // Serwer usunął wiersz razem z tokenem Sanctum. Gdyby zapis został,
    // następna osoba na tym telefonie zobaczyłaby „powiadomienia włączone"
    // dla urządzenia, którego nie ma (decyzja 353).
    final FakePushApi api = FakePushApi();
    final InMemorySecureStore store = InMemorySecureStore();
    await remember(store, token: 'tokenFcmTestowy');
    final FakePushService service = FakePushService(
      systemPermission: PushPermission.granted,
    );
    final ProviderContainer container = containerFor(
      api,
      service,
      store: store,
    );
    await container.read(pushProvider.notifier).check();
    expect(container.read(pushProvider).status, PushStatus.on);

    await container.read(authProvider.notifier).logout();

    expect(api.logouts, 1);
    expect(await stored(store), isNull);
    expect(service.deletions, 1);
    expect(container.read(pushProvider).status, PushStatus.off);
  });
}
