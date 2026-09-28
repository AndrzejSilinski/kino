// Wskaźnik połączenia na żywo. Oba widgety dostają status parametrem, więc
// test nie potrzebuje ani gniazda, ani providerów.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/features/booking/realtime_badge.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

void main() {
  testWidgets('znaczek mówi, w jakim stanie jest połączenie', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      wrap(const RealtimeBadge(status: RealtimeStatus.live)),
    );
    expect(find.byTooltip('Plan sali na żywo'), findsOneWidget);

    await tester.pumpWidget(
      wrap(const RealtimeBadge(status: RealtimeStatus.offline)),
    );
    expect(find.byTooltip('Brak połączenia na żywo'), findsOneWidget);
  });

  testWidgets('przy połączeniu na żywo nie ma żadnego ostrzeżenia', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      wrap(RealtimeWarning(status: RealtimeStatus.live, onRefresh: () {})),
    );

    expect(find.byType(Text), findsNothing);
  });

  testWidgets('brak połączenia ostrzega i daje przycisk odświeżenia', (
    WidgetTester tester,
  ) async {
    int refreshed = 0;
    await tester.pumpWidget(
      wrap(
        RealtimeWarning(
          status: RealtimeStatus.offline,
          onRefresh: () => refreshed++,
        ),
      ),
    );

    expect(find.textContaining('może być nieaktualny'), findsOneWidget);

    await tester.tap(find.text('Odśwież'));
    await tester.pump();

    expect(refreshed, 1);
  });

  testWidgets('w trakcie łączenia mówimy o łączeniu, bez przycisku', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        RealtimeWarning(status: RealtimeStatus.connecting, onRefresh: () {}),
      ),
    );

    expect(find.textContaining('Łączę'), findsOneWidget);
    expect(find.text('Odśwież'), findsNothing);
  });
}
