// Atrapa gniazda WebSocketa i pomocniki do klienta czasu rzeczywistego.
//
// Wspólne dla testów protokołu (`test/core/realtime_test.dart`) i testów stanu
// wyboru miejsc: oba zestawy muszą widzieć DOKŁADNIE to samo gniazdo, inaczej
// zaczną się rozjeżdżać — tak jak wcześniej atrapa API, którą z tego samego
// powodu wyciągnąłem do `booking_api.dart`.

import 'dart:async';
import 'dart:convert';

import 'package:cinema/core/realtime.dart';

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
