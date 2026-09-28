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

import 'dart:async';
import 'dart:convert';

import 'package:cinema/core/realtime.dart';
import 'package:flutter_test/flutter_test.dart';

class FakeSocket implements RealtimeSocket {
  FakeSocket({this.failToConnect = false});

  final bool failToConnect;
  final StreamController<String> _incoming = StreamController<String>();
  final List<Map<String, Object?>> sent = <Map<String, Object?>>[];
  bool closed = false;

  @override
  Future<void> get ready => failToConnect
      ? Future<void>.error(StateError('brak sieci'))
      : Future<void>.value();

  @override
  Stream<String> get messages => _incoming.stream;

  @override
  void send(String text) => sent.add(jsonDecode(text) as Map<String, Object?>);

  @override
  Future<void> close() async {
    closed = true;
    if (!_incoming.isClosed) {
      await _incoming.close();
    }
  }

  /// Wiadomość od serwera. `data` jako NAPIS z JSON-em — tak wysyła Pusher.
  ///
  /// Mapę składamy imperatywnie, a nie `collection-if` w literale: analizator
  /// z `--fatal-infos` domaga się wtedy znacznika `?` (pułapka DI).
  void server(String event, {Object? data, String? channel}) {
    if (_incoming.isClosed) {
      return;
    }
    final Map<String, Object?> message = <String, Object?>{'event': event};
    if (data != null) {
      message['data'] = data is String ? data : jsonEncode(data);
    }
    if (channel != null) {
      message['channel'] = channel;
    }
    _incoming.add(jsonEncode(message));
  }

  /// Wiadomość, w której `data` jest obiektem, nie napisem.
  void serverRaw(Map<String, Object?> message) {
    if (!_incoming.isClosed) {
      _incoming.add(jsonEncode(message));
    }
  }

  void breakLink() {
    if (!_incoming.isClosed) {
      _incoming.addError(StateError('zerwane'));
    }
  }

  List<Map<String, Object?>> ofType(String event) => sent
      .where((Map<String, Object?> message) => message['event'] == event)
      .toList(growable: false);
}

/// Otwiera kolejne atrapy i pamięta je w kolejności.
class SocketLog {
  final List<FakeSocket> opened = <FakeSocket>[];

  /// Które z kolei gniazda mają nie wstać (indeks = numer otwarcia).
  final Set<int> failing = <int>{};

  FakeSocket get last => opened.last;

  RealtimeSocket open(Uri url) {
    final FakeSocket socket = FakeSocket(
      failToConnect: failing.contains(opened.length),
    );
    opened.add(socket);
    return socket;
  }
}

/// Oddaje sterowanie pętli zdarzeń na kilka milisekund.
Future<void> tick([int milliseconds = 5]) =>
    Future<void>.delayed(Duration(milliseconds: milliseconds));

const String channel = 'private-screenings.380';

RealtimeClient clientFor(
  SocketLog sockets, {
  // Domyślnie tak długo, że w trakcie testu nic samo nie tyknie.
  Duration activity = const Duration(seconds: 5),
  Duration pong = const Duration(seconds: 5),
  Duration backoff = const Duration(milliseconds: 10),
  ChannelAuthorizer? authorize,
}) => RealtimeClient(
  url: Uri.parse('ws://localhost:8080/app/klucz'),
  open: sockets.open,
  authorize:
      authorize ?? (String name, String socketId) async => 'klucz:podpis',
  activityTimeout: activity,
  pongTimeout: pong,
  backoff: backoff,
);

/// Połączenie doprowadzone do stanu live wraz z subskrypcją kanału.
Future<void> establish(SocketLog sockets, RealtimeClient client) async {
  await client.subscribe(channel);
  await tick();
  sockets.last.server(
    'pusher:connection_established',
    data: <String, Object?>{'socket_id': '1.2'},
  );
  await tick();
  sockets.last.server(
    'pusher_internal:subscription_succeeded',
    data: <String, Object?>{},
    channel: channel,
  );
  await tick();
}

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
