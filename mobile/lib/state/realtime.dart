// Providery czasu rzeczywistego: jedno gniazdo na aplikację, kanały per ekran.
//
// Decyzja 306: JEDEN klient WebSocketa dla całej aplikacji, a nie jeden na
// ekran. Protokół Pushera multipleksuje kanały na jednym połączeniu, więc
// osobne gniazdo na każdy ekran to bez potrzeby kolejny handshake, kolejny
// ping i kolejne wznawianie po wyjściu z tunelu. Kanały dochodzą i odchodzą
// razem z ekranami (`subscribe` / `unsubscribe`), połączenie zostaje.
//
// Adres gniazda składamy z DWÓCH źródeł: host, port i schemat z parametru
// buildu (`AppConfig`), a ścieżkę i klucz publiczny z `client-config`. Klucza
// nie ma w aplikacji — przychodzi z serwera, tak samo jak w SPA.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/core/realtime_socket.dart';
import 'package:cinema/data/broadcast_auth_repository.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<BroadcastAuthRepository> broadcastAuthRepositoryProvider =
    Provider<BroadcastAuthRepository>(
      (Ref ref) => BroadcastAuthRepository(ref.watch(apiClientProvider)),
    );

/// Klient czasu rzeczywistego. Powstaje po pobraniu `client-config`, bo dopiero
/// stamtąd znamy klucz i ścieżkę gniazda.
final FutureProvider<RealtimeClient> realtimeClientProvider =
    FutureProvider<RealtimeClient>((Ref ref) async {
      final ClientConfig config = await ref.watch(clientConfigProvider.future);
      final BroadcastAuthRepository auth = ref.watch(
        broadcastAuthRepositoryProvider,
      );
      final RealtimeClient client = RealtimeClient(
        url: realtimeUri(
          apiBaseUrl: ref.watch(appConfigProvider).apiBaseUrl,
          path: config.realtime.path,
          key: config.realtime.key,
        ),
        authorize: auth.authorize,
        open: ChannelSocket.new,
      );
      ref.onDispose(client.dispose);
      await client.connect();
      return client;
    });

/// Stan połączenia. Pierwsza wartość to stan bieżący, bo strumień zgłasza
/// tylko ZMIANY — ekran wchodzący na plan sali musi od razu wiedzieć, czy
/// patrzy na dane na żywo.
final StreamProvider<RealtimeStatus> realtimeStatusProvider =
    StreamProvider<RealtimeStatus>((Ref ref) async* {
      final RealtimeClient client = await ref.watch(
        realtimeClientProvider.future,
      );
      yield client.status;
      yield* client.statuses;
    });

/// Zgłoszenie „odtworzyłem kanały po zerwaniu łącza”. Ekran musi wtedy pobrać
/// pełny stan przez REST (wymóg 1.3) — zaległych zdarzeń nikt nie powtórzy.
final StreamProvider<void> realtimeResubscribedProvider = StreamProvider<void>((
  Ref ref,
) async* {
  final RealtimeClient client = await ref.watch(realtimeClientProvider.future);
  yield* client.resubscribed;
});

/// Zdarzenia planu sali jednego seansu. Subskrypcja żyje tyle, ile provider,
/// więc kanał odchodzi razem z ekranem.
final screeningEventsProvider = StreamProvider.family<RealtimeEvent, int>((
  Ref ref,
  int screeningId,
) async* {
  final RealtimeClient client = await ref.watch(realtimeClientProvider.future);
  final String channel = 'private-screenings.$screeningId';
  ref.onDispose(() => client.unsubscribe(channel));
  await client.subscribe(channel);
  yield* client.events.where((RealtimeEvent event) => event.channel == channel);
});
