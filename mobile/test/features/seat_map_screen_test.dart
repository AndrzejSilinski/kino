// Ekran wyboru miejsc — to tu klient wydaje pieniądze, więc test sprawdza nie
// tylko „czy się rysuje”, ale też czy nie da się kliknąć w cudze miejsce
// i czy konflikt 409 widać na foteli, a nie tylko w komunikacie.

import 'dart:async';

import 'package:cinema/core/realtime.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/features/booking/seat_map_screen.dart';
import 'package:cinema/features/booking/seat_tile.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/booking_api.dart';
import '../fixtures/fake_socket.dart';

Widget screenWith(FakeBookingApi api, {RealtimeClient? realtime}) =>
    ProviderScope(
      retry: noRetry,
      overrides: [
        httpClientProvider.overrideWithValue(
          MockClient((http.Request request) async => api.handle(request)),
        ),
        secureStoreProvider.overrideWithValue(InMemorySecureStore()),
        // Ekran pokazuje stan połączenia i subskrybuje kanał seansu, więc bez
        // atrapy gniazda test otwierałby prawdziwy WebSocket (pułapka DM).
        realtimeClientProvider.overrideWithValue(
          AsyncData<RealtimeClient>(realtime ?? clientFor(SocketLog())),
        ),
      ],
      child: const MaterialApp(
        home: SeatMapScreen(screeningId: testScreeningId),
      ),
    );

Finder seatFinder(int id) => find.byKey(ValueKey<String>('seat-$id'));

SeatTile tileOf(WidgetTester tester, int id) =>
    tester.widget<SeatTile>(seatFinder(id));

void main() {
  testWidgets('rysuje plan sali z ekranem, legendą i licznikiem wolnych', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(screenWith(FakeBookingApi()));
    await tester.pumpAndSettle();

    expect(find.text('EKRAN'), findsOneWidget);
    expect(find.text('Interstellar'), findsOneWidget);
    expect(find.text('Wolne: 4 z 8'), findsOneWidget);
    // Osiem foteli z fikstury, każdy pod swoim kluczem.
    expect(find.byType(SeatTile), findsNWidgets(8));
    // Legenda pokazuje kategorie cenowe z cennika seansu.
    expect(find.text('Standardowe 25,30 zł'), findsOneWidget);
    expect(find.text('Twój wybór'), findsOneWidget);
    expect(find.text('Wybierz miejsca na planie'), findsOneWidget);
  });

  testWidgets('status foteli bierze się z planu sali i koszyka', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      screenWith(FakeBookingApi()..cart = cartOf(<int>[103])),
    );
    await tester.pumpAndSettle();

    expect(tileOf(tester, 102).status, SeatStatus.free);
    expect(tileOf(tester, 103).status, SeatStatus.heldByYou);
    expect(tileOf(tester, 104).status, SeatStatus.held);
    expect(tileOf(tester, 105).status, SeatStatus.sold);
    expect(tileOf(tester, 106).status, SeatStatus.unavailable);
  });

  testWidgets('w cudze, sprzedane i wyłączone miejsce nie da się kliknąć', (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = FakeBookingApi();
    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    expect(tileOf(tester, 104).onTap, isNull);
    expect(tileOf(tester, 105).onTap, isNull);
    expect(tileOf(tester, 106).onTap, isNull);
    expect(tileOf(tester, 102).onTap, isNotNull);
    // Wolne miejsce bez ceny zostaje klikalne, ale tylko po to, żeby
    // odpowiedzieć komunikatem — bez żądania do serwera (test niżej).
    expect(tileOf(tester, 107).onTap, isNotNull);

    await tester.tap(seatFinder(105));
    await tester.pumpAndSettle();

    expect(api.lockCalls, 0);
  });

  testWidgets(
    'kliknięcie w wolny fotel dodaje go do koszyka z sumą i timerem',
    (WidgetTester tester) async {
      final FakeBookingApi api = FakeBookingApi(
        onLock: (List<int> ids) => reply(cartOf(ids), 201),
      );
      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      await tester.tap(seatFinder(102));
      await tester.pumpAndSettle();

      expect(api.lockCalls, 1);
      expect(tileOf(tester, 102).status, SeatStatus.heldByYou);
      expect(find.text('Wybrane: A2'), findsOneWidget);
      expect(find.text('1 × miejsce · 25,30 zł'), findsOneWidget);
      // 540 sekund z serwera to 9:00 na ekranie.
      expect(find.text('9:00'), findsOneWidget);
      expect(find.text('Wyczyść wybór'), findsOneWidget);
    },
  );

  testWidgets('odliczanie idzie w dół', (WidgetTester tester) async {
    await tester.pumpWidget(
      screenWith(FakeBookingApi()..cart = cartOf(<int>[103])),
    );
    await tester.pumpAndSettle();
    expect(find.text('9:00'), findsOneWidget);

    await tester.pump(const Duration(seconds: 1));

    expect(find.text('8:59'), findsOneWidget);
  });

  testWidgets('konflikt 409 pokazuje komunikat i przemalowuje fotel', (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = FakeBookingApi(
      onLock: (List<int> ids) => reply(<String, Object?>{
        'message': 'Miejsca A2 zostały właśnie zajęte przez kogoś innego.',
        'code': 'SEATS_UNAVAILABLE',
        'context': <String, Object?>{
          'seat_ids': ids,
          'seats': <String>['A2'],
        },
      }, 409),
    );
    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    await tester.tap(seatFinder(102));
    await tester.pumpAndSettle();

    expect(
      find.text('Miejsca A2 zostały właśnie zajęte przez kogoś innego.'),
      findsOneWidget,
    );
    expect(tileOf(tester, 102).status, SeatStatus.held);
    expect(tileOf(tester, 102).onTap, isNull);
    // Plan sali pobraliśmy tylko raz — 409 przyniósł wszystko, co potrzebne.
    expect(api.seatMapCalls, 1);
  });

  testWidgets('komunikat da się zamknąć', (WidgetTester tester) async {
    await tester.pumpWidget(screenWith(FakeBookingApi()));
    await tester.pumpAndSettle();

    await tester.tap(seatFinder(107));
    await tester.pumpAndSettle();
    expect(find.textContaining('nie ma ceny'), findsOneWidget);

    await tester.tap(find.byTooltip('Zamknij'));
    await tester.pumpAndSettle();

    expect(find.textContaining('nie ma ceny'), findsNothing);
  });

  testWidgets('wyczyszczenie wyboru opróżnia koszyk', (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = FakeBookingApi(
      onDelete: (String path) => reply(cartOf(<int>[])),
    )..cart = cartOf(<int>[103]);
    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Wyczyść wybór'));
    await tester.pumpAndSettle();

    expect(api.deleteCalls, 1);
    expect(find.text('Wybierz miejsca na planie'), findsOneWidget);
    expect(tileOf(tester, 103).status, SeatStatus.free);
  });

  testWidgets('rozpoczęta płatność zamraża plan sali', (
    WidgetTester tester,
  ) async {
    final Map<String, Object?> withPending = cartOf(<int>[103]);
    (withPending['data']!
        as Map<String, Object?>)['pending_booking'] = <String, Object?>{
      'reference': '01K6ABCDEFGHJKMNPQRSTVWXYZ',
      'expires_at': '2026-09-28T15:45:52+00:00',
      'expires_in_seconds': 300,
    };
    final FakeBookingApi api = FakeBookingApi()..cart = withPending;

    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    expect(find.textContaining('Płatność za te miejsca'), findsOneWidget);
    expect(tileOf(tester, 102).onTap, isNull);
    expect(tileOf(tester, 103).onTap, isNull);
  });

  testWidgets(
    'ekran pokazuje stan podglądu na żywo i ostrzega przed nim',
    timeout: const Timeout(Duration(seconds: 30)),
    (WidgetTester tester) async {
      // Dopóki gniazdo nie dostanie handshake'u, klient jest w stanie „łączę” —
      // i to właśnie ten stan ekran ma pokazać. Trzy stany wskaźnika sprawdza
      // osobno realtime_badge_test; tutaj chodzi o SPIĘCIE ekranu ze stanem.
      final SocketLog sockets = SocketLog();
      final RealtimeClient realtime = clientFor(sockets);

      await tester.pumpWidget(screenWith(FakeBookingApi(), realtime: realtime));
      await tester.pumpAndSettle();

      expect(find.byTooltip('Łączę z podglądem na żywo'), findsOneWidget);
      expect(find.textContaining('Łączę z podglądem na żywo'), findsOneWidget);
      // Plan sali działa niezależnie od podglądu na żywo.
      expect(find.byType(SeatTile), findsNWidgets(8));

      sockets.last.server(
        'pusher:connection_established',
        data: <String, Object?>{'socket_id': '1.2'},
      );
      await tester.pumpAndSettle();

      expect(find.byTooltip('Plan sali na żywo'), findsOneWidget);
      expect(find.textContaining('Łączę'), findsNothing);

      // Klienta zamykamy W CIELE testu, nie w `addTearDown` (pułapka DP): po
      // handshake'u chodzi timer ciszy, a test widgetów sprawdza brak zaległych
      // timerów ZANIM wykonają się sprzątania testu.
      //
      // I BEZ `await` (pułapka DQ): `dispose` anuluje timery synchronicznie, więc
      // to wystarczy, żeby sprawdzenie przeszło — ale czekanie na zamknięcie
      // strumienia gniazda w strefie testów widgetów nie wraca i test wisi do
      // limitu czasu. W zwykłym `test()` to samo `await` działa bez zarzutu.
      unawaited(realtime.dispose());
    },
  );

  testWidgets('błąd pobierania planu daje przycisk ponowienia', (
    WidgetTester tester,
  ) async {
    final FakeBookingApi api = FakeBookingApi(
      onSeatMap: () => reply(<String, Object?>{
        'message': 'Nie znaleziono seansu.',
        'code': 'RESOURCE_NOT_FOUND',
      }, 404),
    );

    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    expect(find.text('Nie znaleziono seansu.'), findsOneWidget);
    expect(find.text('Spróbuj ponownie'), findsOneWidget);
    expect(find.byType(SeatTile), findsNothing);
  });
}
