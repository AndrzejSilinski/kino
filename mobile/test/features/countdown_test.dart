// Odliczanie do wygaśnięcia blokady. Trzy rzeczy, które muszą działać:
// format, restart po nowej wartości z serwera i zgłoszenie zera.
//
// Uwaga o testach z zegarem: `pumpAndSettle` przy timerze cyklicznym kończy
// się normalnie, bo między tyknięciami nie ma zaplanowanej klatki. Czas
// przesuwamy jawnie przez `pump(Duration(...))` — nic tu nie czeka na realny
// upływ sekundy.

import 'package:cinema/features/common/countdown.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget withCountdown(int seconds, {VoidCallback? onExpired}) => MaterialApp(
  home: Scaffold(
    body: Countdown(seconds: seconds, onExpired: onExpired),
  ),
);

void main() {
  testWidgets('pokazuje minuty i sekundy', (WidgetTester tester) async {
    await tester.pumpWidget(withCountdown(540));

    expect(find.text('9:00'), findsOneWidget);

    await tester.pump(const Duration(seconds: 1));
    expect(find.text('8:59'), findsOneWidget);

    await tester.pump(const Duration(seconds: 59));
    expect(find.text('8:00'), findsOneWidget);
  });

  testWidgets('nowa wartość z serwera zaczyna odliczanie od nowa', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(withCountdown(600));
    await tester.pump(const Duration(seconds: 5));
    expect(find.text('9:55'), findsOneWidget);

    // Po dołożeniu miejsca serwer przysyła nowy czas najwcześniejszej blokady.
    await tester.pumpWidget(withCountdown(120));

    expect(find.text('2:00'), findsOneWidget);
  });

  testWidgets('dojście do zera zgłasza wygaśnięcie dokładnie raz', (
    WidgetTester tester,
  ) async {
    int expired = 0;
    await tester.pumpWidget(withCountdown(2, onExpired: () => expired++));

    await tester.pump(const Duration(seconds: 1));
    expect(expired, 0);

    await tester.pump(const Duration(seconds: 1));
    expect(find.text('0:00'), findsOneWidget);
    expect(expired, 1);

    // Timer jest anulowany, więc dalsze klatki już nic nie zgłaszają.
    await tester.pump(const Duration(seconds: 5));
    expect(expired, 1);
  });

  testWidgets('zero sekund nie uruchamia timera', (WidgetTester tester) async {
    int expired = 0;
    await tester.pumpWidget(withCountdown(0, onExpired: () => expired++));

    expect(find.text('0:00'), findsOneWidget);
    await tester.pump(const Duration(seconds: 3));
    expect(expired, 0);
  });
}
