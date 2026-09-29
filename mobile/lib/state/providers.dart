// Providery Riverpod — jedno miejsce, w którym składamy zależności (decyzja 258).
//
// Riverpod bez generatora kodu: providery pisane ręcznie, bez `build_runner`.
// W testach podmieniamy je przez `overrides`, więc żaden test nie dotyka sieci.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/data/auth_repository.dart';
import 'package:cinema/data/devices_repository.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/push.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:http/http.dart' as http;

/// Wyłącza automatyczne ponawianie providerów (decyzja 270).
///
/// Riverpod 3 domyślnie ponawia nieudany provider 10 razy z narastającym
/// opóźnieniem (200 ms do 6,4 s), a w trakcie ponawiania stan jest oznaczony
/// jako ładowanie. Dla aplikacji kina to złe zachowanie z dwóch powodów:
/// użytkownik widziałby kręcące się kółko zamiast komunikatu i przycisku
/// „Spróbuj ponownie”, a ciche powtórki biłyby w limity zapytań serwera
/// (blokowanie miejsc ma limit 30 na minutę, logowanie 5). Ponawiamy tylko
/// wtedy, gdy użytkownik o to poprosi.
///
/// Zwracane `null` oznacza „nie ponawiaj”; funkcja jest przekazywana do
/// `ProviderScope(retry: noRetry)` w `main.dart` i w testach widgetów.
Duration? noRetry(int retryCount, Object error) => null;

/// Adres API z parametrów buildu.
final Provider<AppConfig> appConfigProvider = Provider<AppConfig>(
  (Ref ref) => AppConfig.fromEnvironment(),
);

/// Jedno połączenie HTTP na cały czas życia aplikacji (keep-alive).
final Provider<http.Client> httpClientProvider = Provider<http.Client>((
  Ref ref,
) {
  final http.Client client = http.Client();
  ref.onDispose(client.close);
  return client;
});

/// Magazyn na token i sesję zakupową. W testach podmieniany na wersję
/// w pamięci, żeby nie wołać wtyczki natywnej.
final Provider<SecureStore> secureStoreProvider = Provider<SecureStore>(
  (Ref ref) => const KeystoreSecureStore(),
);

/// Jedna sesja na cały czas życia aplikacji: token bearer i identyfikator
/// sesji zakupowej (decyzja 267).
final Provider<AppSession> sessionProvider = Provider<AppSession>(
  (Ref ref) => AppSession(ref.watch(secureStoreProvider)),
);

final Provider<ApiClient> apiClientProvider = Provider<ApiClient>(
  (Ref ref) => ApiClient(
    config: ref.watch(appConfigProvider),
    httpClient: ref.watch(httpClientProvider),
    session: ref.watch(sessionProvider),
  ),
);

final Provider<AuthRepository> authRepositoryProvider =
    Provider<AuthRepository>(
      (Ref ref) => AuthRepository(ref.watch(apiClientProvider)),
    );

/// Stan zalogowania. `ref.read(authProvider.notifier)` daje metody
/// login/register/logout, a `ref.watch(authProvider)` sam stan.
final NotifierProvider<AuthController, AuthState> authProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);

final Provider<DevicesRepository> devicesRepositoryProvider =
    Provider<DevicesRepository>(
      (Ref ref) => DevicesRepository(ref.watch(apiClientProvider)),
    );

/// Warstwa natywna powiadomień (decyzja 349).
///
/// Do bloku M3 stoi tu atrapa, która mówi „to urządzenie nie odbierze push" —
/// cały stan i ekran powstają wcześniej i działają, a aplikacja zachowuje się
/// dokładnie tak jak na telefonie bez Usług Google. W testach podmieniana na
/// atrapę sterowaną z testu.
final Provider<PushService> pushServiceProvider = Provider<PushService>(
  (Ref ref) => const NoPushService(),
);

/// Projekt Firebase wkompilowany w TĘ aplikację — do porównania z tym, z czego
/// wysyła serwer (decyzja 354). `null` znaczy: APK zbudowano bez
/// `google-services.json`, więc nie ma czego porównywać.
final FutureProvider<PushProject?> pushProjectProvider =
    FutureProvider<PushProject?>(
      (Ref ref) => ref.watch(pushServiceProvider).project(),
    );

/// Powiadomienia push na tym telefonie: sprawdzenie, włączenie, wyłączenie.
final NotifierProvider<PushController, PushState> pushProvider =
    NotifierProvider<PushController, PushState>(PushController.new);

/// Konfiguracja z serwera. `ref.invalidate(clientConfigProvider)` ponawia próbę.
final FutureProvider<ClientConfig> clientConfigProvider =
    FutureProvider<ClientConfig>((Ref ref) async {
      final Map<String, Object?> data = await ref
          .watch(apiClientProvider)
          .getJson('/client-config');
      return ClientConfig.fromJson(data);
    });
