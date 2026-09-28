// Klient WebSocketa w protokole Pushera — pisany ręcznie (decyzja 299).
//
// Dlaczego bez gotowej biblioteki: serwerem jest Reverb, a protokół, którego
// tu potrzebujemy, to pięć komunikatów (`pusher:subscribe`,
// `pusher_internal:subscription_succeeded`, `pusher:ping`, `pusher:pong`,
// `pusher:error`) plus podpis kanału prywatnego z własnego API. Gotowa paczka
// dołożyłaby zależność natywną, której nie kontroluję, a i tak musiałbym
// napisać autoryzację kanału, ponawianie i pobranie pełnego stanu po zerwaniu
// łącza. Kształt komunikatów jest potwierdzony rozpoznaniem fazy 2 na żywym
// serwerze, nie przepisany z dokumentacji.
//
// Trzy rzeczy, od których zależy poprawność ścieżki zakupowej:
//
// 1. PO ZERWANIU ŁĄCZA klient NIE nakłada zaległych zdarzeń — pobiera pełny
//    stan sali przez REST (wymóg 1.3 zadania). Dlatego `resubscribed` jest
//    osobnym strumieniem: ekran wie, że musi się odświeżyć.
// 2. `pusher:ping` wysyłamy sami po ciszy dłuższej niż `activity_timeout`
//    z serwera. Bez tego zerwane łącze w tunelu `adb` albo w sieci komórkowej
//    wygląda jak spokojne połączenie bez zdarzeń — a plan sali jest wtedy
//    nieaktualny i klient wybiera zajęte miejsca.
// 3. Kody błędów Pushera 4000-4099 są OSTATECZNE (zły klucz, brak aplikacji).
//    Ponawianie w takim wypadku to pętla w nieskończoność, więc się zatrzymujemy.

import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

/// Gniazdo tekstowe. Cienka warstwa nad `web_socket_channel`, żeby testy mogły
/// podstawić atrapę i sterować zdarzeniami bez serwera.
abstract interface class RealtimeSocket {
  /// Kończy się, gdy połączenie stoi; błędem, gdy nie udało się go zestawić.
  Future<void> get ready;

  Stream<String> get messages;

  void send(String text);

  Future<void> close();
}

typedef SocketOpener = RealtimeSocket Function(Uri url);

/// Podpis subskrypcji kanału prywatnego z `POST /broadcasting/auth`.
typedef ChannelAuthorizer = Future<String> Function(
  String channel,
  String socketId,
);

/// Zdarzenie z kanału — już z rozpakowanym `data`.
class RealtimeEvent {
  const RealtimeEvent({
    required this.channel,
    required this.event,
    required this.data,
  });

  final String channel;
  final String event;
  final Map<String, Object?> data;
}

enum RealtimeStatus {
  /// Brak połączenia: przed pierwszym łączeniem, po zerwaniu, po błędzie.
  offline,

  /// Łączenie albo ponawianie.
  connecting,

  /// Połączenie stoi i kanały są zasubskrybowane.
  live,
}

/// Adres gniazda Reverba: ten sam host i port co API, schemat `ws`/`wss`,
/// ścieżka i klucz publiczny z `client-config`.
Uri realtimeUri({
  required Uri apiBaseUrl,
  required String path,
  required String key,
}) => apiBaseUrl.replace(
  scheme: apiBaseUrl.scheme == 'https' ? 'wss' : 'ws',
  path: '${apiBaseUrl.path}$path/$key',
  queryParameters: <String, String>{
    'protocol': '7',
    'client': 'flutter',
    'version': '1.0',
  },
);

class RealtimeClient {
  RealtimeClient({
    required this.url,
    required this.authorize,
    required this.open,
    this.activityTimeout = defaultActivityTimeout,
    this.pongTimeout = const Duration(seconds: 10),
    this.backoff = const Duration(seconds: 1),
    this.maxBackoff = const Duration(seconds: 15),
  }) : _activityTimeout = activityTimeout;

  final Uri url;
  final ChannelAuthorizer authorize;
  final SocketOpener open;

  /// Odstęp ciszy, po którym wysyłamy ping — zanim serwer poda swój
  /// w `pusher:connection_established`. Parametr, a nie stała, bo testy
  /// muszą móc zmierzyć to w milisekundach, nie w pół minuty (decyzja 300).
  final Duration activityTimeout;

  /// Ile czekamy na `pusher:pong`, zanim uznamy łącze za martwe.
  final Duration pongTimeout;

  /// Pierwszy odstęp przed ponowieniem; kolejne się podwajają.
  final Duration backoff;

  /// Górna granica odstępu między próbami połączenia.
  final Duration maxBackoff;

  /// Domyślny odstęp ciszy, po którym wysyłamy ping. Serwer podaje swój
  /// w `pusher:connection_established` (u nas 30 s) i wtedy wygrywa jego.
  static const Duration defaultActivityTimeout = Duration(seconds: 30);

  final StreamController<RealtimeEvent> _events =
      StreamController<RealtimeEvent>.broadcast();
  final StreamController<RealtimeStatus> _statuses =
      StreamController<RealtimeStatus>.broadcast();
  final StreamController<void> _resubscribed =
      StreamController<void>.broadcast();

  /// Kanały, na których chcemy być — niezależnie od stanu połączenia.
  final Set<String> _wanted = <String>{};
  final Set<String> _subscribed = <String>{};

  RealtimeSocket? _socket;
  StreamSubscription<String>? _listener;

  /// Numer bieżącego gniazda. Zdarzenia i błędy ze starszego są ignorowane:
  /// zamknięcie martwego gniazda zgłasza `onDone` na subskrypcji, którą właśnie
  /// anulujemy, a anulowanie jest asynchroniczne — bez tego licznika drugie
  /// wejście w `_dropped` planowałoby kolejne ponowienie obok pierwszego
  /// (pułapka DK).
  int _generation = 0;
  String? _socketId;
  Duration _activityTimeout;
  Timer? _activityTimer;
  Timer? _pongTimer;
  Timer? _retryTimer;
  int _attempt = 0;
  bool _closed = false;
  bool _reconnecting = false;
  RealtimeStatus _status = RealtimeStatus.offline;

  Stream<RealtimeEvent> get events => _events.stream;

  Stream<RealtimeStatus> get statuses => _statuses.stream;

  /// Emitowane po ODTWORZENIU subskrypcji po zerwaniu łącza. Ekran musi wtedy
  /// pobrać pełny stan przez REST — zaległych zdarzeń nikt nam nie powtórzy.
  Stream<void> get resubscribed => _resubscribed.stream;

  RealtimeStatus get status => _status;

  /// Komunikat ostatniego ostatecznego błędu (np. zły klucz aplikacji).
  String? get fatalError => _fatalError;
  String? _fatalError;

  Set<String> get subscribedChannels => Set<String>.unmodifiable(_subscribed);

  /// Dołącza kanał do listy chcianych i subskrybuje go, gdy łącze stoi.
  Future<void> subscribe(String channel) async {
    if (_closed || !_wanted.add(channel)) {
      return;
    }
    if (_socket == null && _retryTimer == null) {
      await connect();
      return;
    }
    if (_socketId != null) {
      await _subscribeOne(channel);
    }
  }

  void unsubscribe(String channel) {
    _wanted.remove(channel);
    if (_subscribed.remove(channel)) {
      _send(<String, Object?>{
        'event': 'pusher:unsubscribe',
        'data': <String, Object?>{'channel': channel},
      });
    }
  }

  Future<void> connect() async {
    if (_closed || _socket != null) {
      return;
    }
    _retryTimer?.cancel();
    _retryTimer = null;
    _emit(RealtimeStatus.connecting);
    final RealtimeSocket socket = open(url);
    final int generation = ++_generation;
    _socket = socket;
    _listener = socket.messages.listen(
      (String raw) => _fromSocket(generation, () => _onMessage(raw)),
      onError: (Object error) =>
          _fromSocket(generation, () => _dropped('błąd strumienia')),
      onDone: () =>
          _fromSocket(generation, () => _dropped('połączenie zamknięte')),
      cancelOnError: false,
    );
    try {
      await socket.ready;
    } on Object catch (_) {
      if (generation != _generation) {
        return;
      }
      // Brak sieci, odmowa połączenia, zły adres — ponawiamy z narastającym
      // odstępem, tak samo jak przy zerwaniu w trakcie.
      _dropped('nie udało się połączyć');
    }
  }

  Future<void> dispose() async {
    _closed = true;
    _activityTimer?.cancel();
    _pongTimer?.cancel();
    _retryTimer?.cancel();
    await _listener?.cancel();
    await _socket?.close();
    _socket = null;
    await _events.close();
    await _statuses.close();
    await _resubscribed.close();
  }

  void _emit(RealtimeStatus next) {
    _status = next;
    if (!_statuses.isClosed) {
      _statuses.add(next);
    }
  }

  void _send(Map<String, Object?> message) {
    _socket?.send(jsonEncode(message));
  }

  void _onMessage(String raw) {
    // Każda wiadomość jest dowodem życia łącza, także `pong` i zdarzenia.
    _restartActivityTimer();
    final Object? decoded = jsonDecode(raw);
    if (decoded is! Map<String, Object?>) {
      return;
    }
    final Object? name = decoded['event'];
    if (name is! String) {
      return;
    }
    final Map<String, Object?> data = _payload(decoded['data']);
    switch (name) {
      case 'pusher:connection_established':
        _onEstablished(data);
      case 'pusher_internal:subscription_succeeded':
        final Object? channel = decoded['channel'];
        if (channel is String) {
          _subscribed.add(channel);
        }
      case 'pusher:ping':
        _send(<String, Object?>{
          'event': 'pusher:pong',
          'data': <String, Object?>{},
        });
      case 'pusher:pong':
        break;
      case 'pusher:error':
        _onProtocolError(data);
      default:
        final Object? channel = decoded['channel'];
        if (!_events.isClosed) {
          _events.add(
            RealtimeEvent(
              channel: channel is String ? channel : '',
              event: name,
              data: data,
            ),
          );
        }
    }
  }

  /// `data` w protokole Pushera bywa NAPISEM z JSON-em w środku, a bywa
  /// obiektem. Obsługujemy oba, bo od tego zależy, czy zdarzenie dojdzie.
  Map<String, Object?> _payload(Object? raw) {
    if (raw is Map<String, Object?>) {
      return raw;
    }
    if (raw is String && raw.isNotEmpty) {
      try {
        final Object? decoded = jsonDecode(raw);
        if (decoded is Map<String, Object?>) {
          return decoded;
        }
      } on FormatException {
        return const <String, Object?>{};
      }
    }
    return const <String, Object?>{};
  }

  void _onEstablished(Map<String, Object?> data) {
    final Object? socketId = data['socket_id'];
    if (socketId is! String) {
      return;
    }
    _socketId = socketId;
    final Object? timeout = data['activity_timeout'];
    if (timeout is int && timeout > 0) {
      _activityTimeout = Duration(seconds: timeout);
    }
    _attempt = 0;
    _restartActivityTimer();
    unawaited(_subscribeWanted());
  }

  Future<void> _subscribeWanted() async {
    for (final String channel in _wanted.toList(growable: false)) {
      await _subscribeOne(channel);
    }
    if (_closed) {
      return;
    }
    _emit(RealtimeStatus.live);
    if (_reconnecting) {
      _reconnecting = false;
      if (!_resubscribed.isClosed) {
        _resubscribed.add(null);
      }
    }
  }

  Future<void> _subscribeOne(String channel) async {
    final String? socketId = _socketId;
    if (socketId == null || _subscribed.contains(channel)) {
      return;
    }
    try {
      final String auth = await authorize(channel, socketId);
      if (_socketId != socketId) {
        return; // w trakcie autoryzacji łącze się zmieniło
      }
      _send(<String, Object?>{
        'event': 'pusher:subscribe',
        'data': <String, Object?>{'auth': auth, 'channel': channel},
      });
    } on Object catch (_) {
      // Odmowa podpisu (403) albo brak sieci. Nie ponawiamy w pętli: kanał
      // zostaje na liście chcianych i wejdzie przy następnym połączeniu.
    }
  }

  void _onProtocolError(Map<String, Object?> data) {
    final Object? code = data['code'];
    final Object? message = data['message'];
    final int? number = code is int ? code : null;
    // 4000-4099 to błędy, których ponawianie nie naprawi (zły klucz aplikacji,
    // nieobsługiwana wersja protokołu). Zatrzymujemy się i mówimy to wprost.
    if (number != null && number >= 4000 && number < 4100) {
      _fatalError = message is String ? message : 'błąd protokołu $number';
      _closed = true;
      _teardown();
      _emit(RealtimeStatus.offline);
      return;
    }
    _dropped('błąd protokołu ${number ?? ''}');
  }

  void _restartActivityTimer() {
    _activityTimer?.cancel();
    _pongTimer?.cancel();
    if (_closed) {
      return;
    }
    _activityTimer = Timer(_activityTimeout, () {
      _send(<String, Object?>{
        'event': 'pusher:ping',
        'data': <String, Object?>{},
      });
      _pongTimer = Timer(pongTimeout, () => _dropped('brak pong'));
    });
  }

  void _teardown() {
    _activityTimer?.cancel();
    _pongTimer?.cancel();
    final RealtimeSocket? socket = _socket;
    final StreamSubscription<String>? listener = _listener;
    _socket = null;
    _listener = null;
    _socketId = null;
    _subscribed.clear();
    unawaited(listener?.cancel());
    unawaited(socket?.close());
  }

  /// Wykonuje `action` tylko wtedy, gdy pochodzi od BIEŻĄCEGO gniazda.
  void _fromSocket(int generation, void Function() action) {
    if (generation == _generation) {
      action();
    }
  }

  /// Łącze padło: zamykamy, co zostało, i planujemy kolejną próbę.
  void _dropped(String reason) {
    if (_closed) {
      return;
    }
    // Od tej chwili wszystko, co przyjdzie od starego gniazda, jest ignorowane.
    _generation++;
    _retryTimer?.cancel();
    _teardown();
    _emit(RealtimeStatus.offline);
    _reconnecting = true;
    _attempt++;
    // 1 s, 2 s, 4 s, 8 s, potem stała górna granica. Odstęp jest po to, żeby
    // telefon w windzie nie próbował łączyć się w pętli i nie zjadł baterii.
    final int micros = math.min(
      maxBackoff.inMicroseconds,
      backoff.inMicroseconds * (1 << math.min(_attempt - 1, 4)),
    );
    _retryTimer = Timer(Duration(microseconds: micros), () {
      _retryTimer = null;
      unawaited(connect());
    });
  }
}
