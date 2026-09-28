// Repertuar na ekranie: wybór dnia, karty seansów i — najważniejsze —
// seans wyprzedany, który musi być wyraźnie oznaczony i NIEKLIKALNY.

import 'dart:convert';

import 'package:cinema/features/catalog/cinema_screen.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

Widget screenWith(http.Response Function(http.Request request) handler) =>
    ProviderScope(
      retry: noRetry,
      overrides: [
        httpClientProvider.overrideWithValue(
          MockClient((http.Request request) async => handler(request)),
        ),
      ],
      child: const MaterialApp(home: CinemaScreen(slug: 'gdansk-kino-baltyk')),
    );

http.Response fixtureResponse(String name) => http.Response(
  jsonEncode(envelope(name)),
  200,
  headers: <String, String>{'content-type': 'application/json'},
);

http.Response route(http.Request request) =>
    request.url.path.endsWith('/screening-dates')
    ? fixtureResponse('screening_dates')
    : fixtureResponse('screenings_day');

void main() {
  testWidgets('pokazuje dni i seanse wybranego dnia', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(screenWith(route));
    await tester.pumpAndSettle();

    expect(find.text('18.09'), findsOneWidget);
    // Szukamy KARTY z tytułem, nie samego napisu: tytuł może pojawić się na
    // ekranie więcej niż raz (pułapka CW), a testowi chodzi o pozycję listy.
    expect(find.widgetWithText(ListTile, 'Oppenheimer'), findsOneWidget);
    expect(find.widgetWithText(ListTile, 'Diuna: Część druga'), findsOneWidget);
  });

  testWidgets('wyprzedany seans jest oznaczony i nieklikalny', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(screenWith(route));
    await tester.pumpAndSettle();

    expect(find.text('Wyprzedane'), findsOneWidget);
    final ListTile soldOut = tester.widget<ListTile>(
      find.widgetWithText(ListTile, 'Diuna: Część druga'),
    );
    expect(soldOut.enabled, isFalse);
    expect(soldOut.onTap, isNull);

    final ListTile available = tester.widget<ListTile>(
      find.widgetWithText(ListTile, 'Oppenheimer'),
    );
    expect(available.onTap, isNotNull);
  });

  testWidgets('pusty dzień mówi wprost, że nie ma seansów', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      screenWith((http.Request request) {
        if (request.url.path.endsWith('/screening-dates')) {
          return fixtureResponse('screening_dates');
        }
        final Map<String, Object?> body = envelope('screenings_day');
        body['data'] = <Object?>[];
        return http.Response(
          jsonEncode(body),
          200,
          headers: <String, String>{'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tego dnia nie ma seansów.'), findsOneWidget);
  });
}
