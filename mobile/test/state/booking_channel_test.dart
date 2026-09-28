// Kanał rezerwacji: `private-bookings.{reference}`.
//
// Gniazdo to atrapa, klient wchodzi gotowy przez `overrideWithValue` (pułapka
// DM), więc test chodzi w milisekundach i nie dotyka sieci.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/models/booking_event.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fixtures/fake_socket.dart';

const String reference = '01M3MK53H15CAMZB8DE9AFF06Q';
const String bookingChannel = 'private-bookings.$reference';

class Scene {
  Scene() {
    client = clientFor(sockets);
    container = ProviderContainer(
      retry: noRetry,
      overrides: [
        realtimeClientProvider.overrideWithValue(
          AsyncData<RealtimeClient>(client),
        ),
      ],
    );
    // Ekran jest obserwatorem kanału (pułapka DO) — test też musi nim być.
    container.listen(bookingEventsProvider(reference), (
      AsyncValue<RealtimeEvent>? previous,
      AsyncValue<RealtimeEvent> next,
    ) {
      final RealtimeEvent? event = next.value;
      if (event != null) {
        seen.add(event);
      }
    }, fireImmediately: true);
    // Kolejność ODWROTNA do wykonania: sprzątania idą od ostatniego, więc
    // kontener zamyka się PRZED klientem. Inaczej `unsubscribe` z `onDispose`
    // trafiałby w gniazdo już zamknięte.
    addTearDown(client.dispose);
    addTearDown(close);
  }

  final SocketLog sockets = SocketLog();
  final List<RealtimeEvent> seen = <RealtimeEvent>[];
  late final RealtimeClient client;
  late final ProviderContainer container;
  bool _closed = false;

  /// Zamyka kontener; wolno wołać dwa razy, bo robi to też sprzątanie testu.
  void close() {
    if (_closed) {
      return;
    }
    _closed = true;
    container.dispose();
  }

  /// Doprowadza połączenie do stanu, w którym kanał jest zasubskrybowany.
  Future<void> open() async {
    for (int i = 0; i < 20 && sockets.opened.isEmpty; i++) {
      await tick();
    }
    sockets.last.server(
      'pusher:connection_established',
      data: <String, Object?>{'socket_id': '1.2'},
    );
    await tick();
    sockets.last.server(
      'pusher_internal:subscription_succeeded',
      data: <String, Object?>{},
      channel: bookingChannel,
    );
    await tick();
  }

  void emit({String channel = bookingChannel, String status = 'paid'}) {
    sockets.last.server(
      'booking.status-changed',
      data: <String, Object?>{
        'reference': reference,
        'status': status,
        'status_label': 'Opłacona',
        'occurred_at': '2026-09-28T18:07:12+00:00',
      },
      channel: channel,
    );
  }
}

void main() {
  test(
    'subskrybujemy kanał TEJ rezerwacji',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();

      await scene.open();

      expect(scene.sockets.opened, hasLength(1));
      expect(
        scene.sockets.last.ofType('pusher:subscribe').single['data'],
        <String, Object?>{'auth': 'klucz:podpis', 'channel': bookingChannel},
      );
    },
  );

  test(
    'zdarzenie zmiany statusu dochodzi i daje się odczytać',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();

      scene.emit();
      await tick();

      expect(scene.seen, hasLength(1));
      expect(scene.seen.single.event, 'booking.status-changed');
      final BookingStatusEvent? parsed = BookingStatusEvent.tryParse(
        scene.seen.single.data,
      );
      expect(parsed!.reference, reference);
      expect(parsed.endsWaiting, isTrue);
    },
  );

  test(
    'zdarzenie z CUDZEGO kanału nie dochodzi',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      // Jedno gniazdo obsługuje wiele kanałów (decyzja 306), więc filtrowanie po
      // nazwie kanału jest tu jedyną barierą — i musi trzymać.
      final Scene scene = Scene();
      await scene.open();

      scene.emit(channel: 'private-bookings.01CUDZAREZERWACJA0000000AB');
      scene.emit(channel: 'private-screenings.380');
      await tick();

      expect(scene.seen, isEmpty);
    },
  );

  test(
    'kanał odchodzi razem z providerem',
    timeout: const Timeout(Duration(seconds: 5)),
    () async {
      final Scene scene = Scene();
      await scene.open();

      scene.close();
      await tick();

      // Bez tego jedna wizyta na ekranie płatności zostawiałaby subskrypcję na
      // stałe — po kilku zakupach gniazdo trzymałoby kanały rezerwacji, których
      // nikt już nie czyta.
      expect(
        scene.sockets.last.ofType('pusher:unsubscribe').single['data'],
        <String, Object?>{'channel': bookingChannel},
      );
    },
  );
}
