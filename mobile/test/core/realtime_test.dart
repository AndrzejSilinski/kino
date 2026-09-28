// Klient protokołu Pushera bez serwera i bez sieci: gniazdo podstawiamy atrapą,
// a wszystkie czasy są parametrami klienta, więc w testach mierzymy je
// w milisekundach (decyzja 300).
//
// Pierwsze podejście używało `testWidgets` po sterowany zegar. Skończyło się
// dwoma testami wiszącymi po dziesięć minut (pułapka DL): przebieg bloku wzrósł
// z 90 sekund do 34 minut, a siedem pozostałych testów w tym pliku w ogóle nie
// wystartowało. Zwykły `test()` z realnym zegarem i czasami rzędu 10-40 ms jest
// szybszy, prostszy i nie zależy od tego, jak strefa testów widgetów obsługuje
// timery i strumienie.

import 'package:cinema/core/realtime.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fake_socket.dart';

void main() {
  test('adres gniazda składa się z konfiguracji', () {
    expect(
      realtimeUri(
        apiBaseUrl: Uri.parse('http://localhost:8080'),
        path: '/app',
        key: 'klucz',
      ).toString(),
      'ws://localhost:8080/app/klucz?protocol=7&client=flutter&version=1.0',
    );
    // HTTPS daje wss — w Etapie 10 zmienia się tylko adres, nie kod.
    expect(
      realtimeUri(
        apiBaseUrl: Uri.parse('https://kino.example'),
        path: '/app',
        key: 'klucz',
      ).scheme,
      'wss',
    );
  });

  test('łączy się, podpisuje kanał i zgłasza stan live', () async {
    final SocketLog sockets = SocketLog();
    final List<String> authorized = <String>[];
    final List<RealtimeStatus> statuses = <RealtimeStatus>[];
    final RealtimeClient client = clientFor(
      sockets,
      authorize: (String name, String socketId) async {
        authorized.add('$name|$socketId');
        return 'klucz:podpis';
      },
    );
    client.statuses.listen(statuses.add);

    await establish(sockets, client);

    expect(authorized, <String>['$channel|1.2']);
    expect(
      sockets.last.ofType('pusher:subscribe').single['data'],
      <String, Object?>{'auth': 'klucz:podpis', 'channel': channel},
    );
    expect(client.status, RealtimeStatus.live);
    expect(client.subscribedChannels, <String>{channel});
    expect(statuses, <RealtimeStatus>[
      RealtimeStatus.connecting,
      RealtimeStatus.live,
    ]);

    await client.dispose();
  });

  test('zdarzenie kanału dochodzi z rozpakowanym data', () async {
    final SocketLog sockets = SocketLog();
    final List<RealtimeEvent> events = <RealtimeEvent>[];
    final RealtimeClient client = clientFor(sockets);
    client.events.listen(events.add);
    await establish(sockets, client);

    // Pusher pakuje payload w NAPIS z JSON-em...
    sockets.last.server(
      'seats.changed',
      data: <String, Object?>{
        'screening_id': 380,
        'version': 5,
        'seats': <String, Object?>{
          'held': <int>[890],
        },
      },
      channel: channel,
    );
    // ...ale bywa też obiektem. Oba kształty muszą dojść.
    sockets.last.serverRaw(<String, Object?>{
      'event': 'seats.resync',
      'channel': channel,
      'data': <String, Object?>{'screening_id': 380, 'version': 6},
    });
    await tick();

    expect(events.length, 2);
    expect(events.first.event, 'seats.changed');
    expect(events.first.channel, channel);
    expect(events.first.data['version'], 5);
    expect((events.first.data['seats']! as Map<String, Object?>)['held'], <int>[
      890,
    ]);
    expect(events.last.event, 'seats.resync');
    expect(events.last.data['version'], 6);

    await client.dispose();
  });

  test('cisza wysyła ping, a pong utrzymuje połączenie', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(
      sockets,
      activity: const Duration(milliseconds: 40),
      pong: const Duration(milliseconds: 200),
    );
    await establish(sockets, client);

    expect(sockets.last.ofType('pusher:ping'), isEmpty);
    await tick(60);
    expect(sockets.last.ofType('pusher:ping'), hasLength(1));

    sockets.last.server('pusher:pong', data: <String, Object?>{});
    await tick(20);

    expect(client.status, RealtimeStatus.live);
    expect(sockets.opened, hasLength(1));

    await client.dispose();
  });

  test('brak pong zrywa łącze i ponawia po odstępie', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(
      sockets,
      activity: const Duration(milliseconds: 20),
      pong: const Duration(milliseconds: 20),
    );
    await establish(sockets, client);

    await tick(60); // ping poszedł, pong nie przyszedł

    expect(sockets.opened.first.closed, isTrue);

    await tick(40); // odstęp minął, klient próbuje znowu

    expect(sockets.opened, hasLength(2));

    await client.dispose();
  });

  test('zerwanie otwiera DOKŁADNIE jedno nowe gniazdo', () async {
    // Strażnik pułapki DK: zamknięcie martwego gniazda zgłasza `onDone` na
    // anulowanej subskrypcji, a bez licznika generacji drugie wejście
    // w obsługę zerwania planowało kolejne ponowienie obok pierwszego.
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(sockets);
    await establish(sockets, client);

    sockets.last.breakLink();
    await tick(40);

    expect(sockets.opened, hasLength(2));

    await tick(80);

    expect(sockets.opened, hasLength(2));

    await client.dispose();
  });

  test('po odtworzeniu subskrypcji zgłasza resubscribed', () async {
    final SocketLog sockets = SocketLog();
    int resubscribed = 0;
    final RealtimeClient client = clientFor(sockets);
    client.resubscribed.listen((_) => resubscribed++);

    await establish(sockets, client);
    // Pierwsze połączenie NIE jest odtworzeniem — nie ma czego nadrabiać.
    expect(resubscribed, 0);

    sockets.last.breakLink();
    await tick();
    expect(client.status, RealtimeStatus.offline);

    await tick(40);
    sockets.last.server(
      'pusher:connection_established',
      data: <String, Object?>{'socket_id': '2.3'},
    );
    await tick();

    expect(sockets.opened, hasLength(2));
    expect(client.status, RealtimeStatus.live);
    // Kanał wrócił bez udziału ekranu...
    expect(sockets.last.ofType('pusher:subscribe'), hasLength(1));
    // ...a ekran dostał znak, że musi pobrać pełny stan przez REST (wymóg 1.3).
    expect(resubscribed, 1);

    await client.dispose();
  });

  test('ping od serwera dostaje pong', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(sockets);
    await client.connect();
    await tick();

    sockets.last.server('pusher:ping', data: <String, Object?>{});
    await tick();

    expect(sockets.last.ofType('pusher:pong'), hasLength(1));

    await client.dispose();
  });

  test('błąd 4001 zatrzymuje ponawianie', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(sockets);
    await client.connect();
    await tick();

    sockets.last.server(
      'pusher:error',
      data: <String, Object?>{
        'code': 4001,
        'message': 'Application does not exist',
      },
    );
    await tick(60);

    expect(client.status, RealtimeStatus.offline);
    expect(client.fatalError, 'Application does not exist');
    expect(sockets.opened, hasLength(1));

    await client.dispose();
  });

  test('błąd 4100 jest ponawiany', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(sockets);
    await client.connect();
    await tick();

    sockets.last.server(
      'pusher:error',
      data: <String, Object?>{'code': 4100, 'message': 'Over capacity'},
    );
    await tick(40);

    expect(sockets.opened, hasLength(2));
    expect(client.fatalError, isNull);

    await client.dispose();
  });

  test('nieudane połączenie też jest ponawiane', () async {
    final SocketLog sockets = SocketLog();
    sockets.failing.add(0); // pierwsze gniazdo nie wstanie
    final RealtimeClient client = clientFor(sockets);

    await client.connect();
    await tick();
    expect(client.status, RealtimeStatus.offline);

    await tick(40);

    expect(sockets.opened, hasLength(2));

    await client.dispose();
  });

  test('rezygnacja z kanału wysyła pusher:unsubscribe', () async {
    final SocketLog sockets = SocketLog();
    final RealtimeClient client = clientFor(sockets);
    await establish(sockets, client);

    client.unsubscribe(channel);
    await tick();

    expect(sockets.last.ofType('pusher:unsubscribe'), hasLength(1));
    expect(client.subscribedChannels, isEmpty);

    await client.dispose();
  });
}
