// Providery Riverpod — jedno miejsce, w którym składamy zależności (decyzja 258).
//
// Riverpod bez generatora kodu: providery pisane ręcznie, bez `build_runner`.
// W testach podmieniamy je przez `overrides`, więc żaden test nie dotyka sieci.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/models/client_config.dart';
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

final Provider<ApiClient> apiClientProvider = Provider<ApiClient>(
  (Ref ref) => ApiClient(
    config: ref.watch(appConfigProvider),
    httpClient: ref.watch(httpClientProvider),
  ),
);

/// Konfiguracja z serwera. `ref.invalidate(clientConfigProvider)` ponawia próbę.
final FutureProvider<ClientConfig> clientConfigProvider =
    FutureProvider<ClientConfig>((Ref ref) async {
      final Map<String, Object?> data = await ref
          .watch(apiClientProvider)
          .getJson('/client-config');
      return ClientConfig.fromJson(data);
    });
