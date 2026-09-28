// Plan sali na żywo: co ekran robi ze zdarzeniami z kanału seansu.
//
// Serwera nie ma — gniazdo to atrapa (`fake_socket.dart`), API to atrapa
// (`booking_api.dart`), a klient czasu rzeczywistego wchodzi do providerów
// przez `overrideWithValue`. Dzięki temu cały ten plik chodzi w milisekundach
// i nie dotyka sieci.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/state/booking.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/booking_api.dart';
import '../fixtures/fake_socket.dart';

class Scene {
  Scene({List<int> cart = const <int>[]}) {
    api = FakeBookingApi()..cart = cartOf(cart);
    client = clientFor(sockets);
    container = ProviderContainer(
      retry: noRetry,
      overrides: [
        httpClientProvider.overrideWithValue(
          MockClient((http.Request request) async => api.handle(request)),
        ),
        secureStoreProvider.overrideWithValue(InMemorySecureStore()),
        // Klient czasu rzeczywistego wchodzi gotowy: bez tego provider
        // zbudowałby prawdziwe gniazdo i test poszedłby do sieci (pułapka DM).
        realtimeClientProvider.overrideWithValue(
          AsyncData<RealtimeClient>(client),
        ),
      ],
    );
    // OBSERWATOR, nie diagnostyka (pułapka DO). Ekran `watch`uje stan wyboru
    // miejsc, więc test też musi go obserwować: bez obserwatora zdarzenia
    // przekazywane przez `ref.listen` ze środka notifiera nie dochodziły do
    // stanu, a testy kończyły się limitem czasu zamiast błędem. Sam `read`
    // nie odtwarza sytuacji z ekranu.
    //
    // Przy okazji zapisujemy KAŻDE przejście stanu. Dzięki temu test potrafi
    // udowodnić nie tylko wynik, ale też że po drodze NIE było ponownego
    // ładowania — czyli że zdarzenie nałożyło się na plan, a nie spowodowało
    // pobrania całej sali od nowa.
    container.listen(seatSelectionProvider(testScreeningId), (
      AsyncValue<SeatSelectionState>? previous,
      AsyncValue<SeatSelectionState> next,
    ) {
      transitions.add(
        next is AsyncLoading
            ? 'ladowanie'
            : 'dane v=${next.value?.map.version}',
      );
    }, fireImmediately: true);
    addTearDown(container.dispose);
    addTearDown(client.dispose);
  }

  /// Kolejne stany providera od wejścia na ekran.
  final List<String> transitions = <String>[];

  late final FakeBookingApi api;
  final SocketLog sockets = SocketLog();
  late final RealtimeClient client;
  late final ProviderContainer container;

  SeatSelectionState get state =>
      container.read(seatSelectionProvider(testScreeningId)).requireValue;

  /// Wejście na ekran: pobranie stanu i dojście połączenia do stanu live.
  Future<void> open() async {
    await container.read(seatSelectionProvider(testScreeningId).future);
    // Subskrypcja kanału idzie przez providera, czyli asynchronicznie. Czekamy
    // na gniazdo, zamiast zakładać, że powstało w tym samym obrocie pętli.
    for (int i = 0; i < 20 && sockets.opened.isEmpty; i++) {
      await tick();
    }
    handshake('1.2');
    await tick();
  }

  void handshake(String socketId) {
    sockets.last.server(
      'pusher:connection_established',
      data: <String, Object?>{'socket_id': socketId},
    );
  }

  /// Zdarzenie od serwera na kanale tego seansu.
  void emit(
    String name, {
    required int version,
    Map<String, Object?> seats = const <String, Object?>{},
    int screeningId = testScreeningId,
  }) {
    sockets.last.server(
      name,
      data: <String, Object?>{
        'screening_id': screeningId,
        'version': version,
        'seats': seats,
      },
      channel: 'private-screenings.$testScreeningId',
    );
  }
}

void main() {
  test(
    'wejście na plan sali subskrybuje kanał seansu',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();

      await scene.open();
      await tick();

      expect(scene.sockets.opened, hasLength(1));
      expect(
        scene.sockets.last.ofType('pusher:subscribe').single['data'],
        <String, Object?>{
          'auth': 'klucz:podpis',
          'channel': 'private-screenings.$testScreeningId',
        },
      );
    },
  );

  test(
    'cudza blokada ze zdarzenia przemalowuje fotel',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();
      expect(scene.state.map.seats[101]!.status, SeatStatus.free);

      scene.emit(
        'seats.changed',
        version: 9,
        seats: <String, Object?>{
          'held': <int>[101],
        },
      );
      await tick();

      expect(scene.state.map.version, 9);
      expect(
        scene.state.statusOf(scene.state.map.seats[101]!),
        SeatStatus.held,
      );
      // Plan sali nie był pobierany drugi raz — zdarzenie wystarczyło. Widać to
      // w przejściach stanu: po danych z wersją 7 od razu dane z wersją 9, bez
      // ładowania po drodze.
      expect(scene.api.seatMapCalls, 1);
      expect(scene.transitions, <String>['ladowanie', 'dane v=7', 'dane v=9']);
    },
  );

  test(
    'MOJE miejsca zostają moje, choć broadcast mówi o nich held',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      // To jest sedno decyzji 285: payload nie zależy od tego, kto słucha.
      final Scene scene = Scene(cart: <int>[103]);
      await scene.open();
      await tick();

      scene.emit(
        'seats.changed',
        version: 9,
        seats: <String, Object?>{
          'held': <int>[101, 103],
        },
      );
      await tick();

      expect(
        scene.state.statusOf(scene.state.map.seats[101]!),
        SeatStatus.held,
      );
      expect(
        scene.state.statusOf(scene.state.map.seats[103]!),
        SeatStatus.heldByYou,
      );
    },
  );

  test(
    'zdarzenie ze starszą wersją jest pomijane',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();
      expect(scene.state.map.version, 7);

      // Fikstura planu sali ma wersję 7; zdarzenie z 7 jest już uwzględnione.
      scene.emit(
        'seats.changed',
        version: 7,
        seats: <String, Object?>{
          'sold': <int>[101],
        },
      );
      await tick();

      expect(scene.state.map.seats[101]!.status, SeatStatus.free);
      expect(scene.state.map.version, 7);
    },
  );

  test(
    'zdarzenie z innego seansu nie rusza tego planu',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();

      scene.emit(
        'seats.changed',
        version: 99,
        screeningId: 999,
        seats: <String, Object?>{
          'sold': <int>[101],
        },
      );
      await tick();

      expect(scene.state.map.version, 7);
      expect(scene.state.map.seats[101]!.status, SeatStatus.free);
    },
  );

  test(
    'seats.resync pobiera pełny stan przez REST',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();
      expect(scene.api.seatMapCalls, 1);

      scene.emit('seats.resync', version: 30);
      await tick(20);

      expect(scene.api.seatMapCalls, 2);
    },
  );

  test(
    'zdarzenie bez wersji nie wywraca ekranu',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();

      scene.sockets.last.server(
        'seats.changed',
        data: <String, Object?>{'screening_id': testScreeningId},
        channel: 'private-screenings.$testScreeningId',
      );
      await tick();

      // Stan dalej jest stanem, nie błędem — jedna dziwna ramka nie gasi ekranu.
      expect(
        scene.container.read(seatSelectionProvider(testScreeningId)).hasError,
        isFalse,
      );
      expect(scene.state.map.version, 7);
    },
  );

  test(
    'po powrocie zerwanego łącza pobieramy plan i koszyk',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();
      await tick();
      expect(scene.api.seatMapCalls, 1);

      scene.sockets.last.breakLink();
      await tick(40); // odstęp ponowienia minął
      expect(scene.sockets.opened, hasLength(2));

      scene.handshake('2.3'); // kanały wracają, leci resubscribed
      await tick(30);

      // Zaległych zdarzeń nikt nie powtórzy, więc stan bierzemy w całości (1.3).
      expect(scene.api.seatMapCalls, 2);
    },
  );
}
