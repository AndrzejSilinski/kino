// Gniazdo WebSocketa na `web_socket_channel` — jedyne miejsce w aplikacji,
// które zna tę paczkę.
//
// Reszta klienta czasu rzeczywistego widzi tylko interfejs `RealtimeSocket`
// (napisy w jedną i drugą stronę). Dzięki temu testy protokołu Pushera idą bez
// serwera i bez sieci, a podmiana paczki na inną nie dotyka logiki.

import 'dart:convert';

import 'package:cinema/core/realtime.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

class ChannelSocket implements RealtimeSocket {
  ChannelSocket(Uri url) : _channel = WebSocketChannel.connect(url);

  final WebSocketChannel _channel;

  @override
  Future<void> get ready => _channel.ready;

  /// Reverb wysyła ramki tekstowe, ale `web_socket_channel` zwraca `dynamic`:
  /// na ramce binarnej dostalibyśmy listę bajtów. Zamieniamy ją na napis,
  /// zamiast wywracać strumień rzutowaniem.
  @override
  Stream<String> get messages => _channel.stream.map((Object? message) {
    if (message is String) {
      return message;
    }
    if (message is List<int>) {
      return utf8.decode(message, allowMalformed: true);
    }
    return '';
  });

  @override
  void send(String text) => _channel.sink.add(text);

  @override
  Future<void> close() => _channel.sink.close();
}
