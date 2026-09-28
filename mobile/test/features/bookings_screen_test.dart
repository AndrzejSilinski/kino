// Ekran historii zakupów: co widać, jak doczytuje i co robi z awarią.

import 'dart:convert';

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/features/bookings/bookings_screen.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

const Timeout limit = Timeout(Duration(seconds: 30));

class FakeHistoryApi {
  FakeHistoryApi({this.onList});

  final http.Response Function(int page)? onList;
  final List<int> pages = <int>[];

  http.Response handle(http.Request request) {
    final int page = int.parse(request.url.queryParameters['page'] ?? '1');
    pages.add(page);
    return onList?.call(page) ??
        reply(envelope(page == 1 ? 'bookings_page' : 'bookings_page2'));
  }
}

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

/// Koperta z pustą historią — kształt ten sam, zero pozycji.
Map<String, Object?> emptyEnvelope() {
  final Map<String, Object?> full = envelope('bookings_page');
  full['data'] = <Object?>[];
  final Map<String, Object?> meta = full['meta']! as Map<String, Object?>;
  meta['current_page'] = 1;
  meta['last_page'] = 1;
  meta['total'] = 0;
  meta['to'] = 0;
  return full;
}

Widget screenWith(FakeHistoryApi api) => ProviderScope(
  retry: noRetry,
  overrides: [
    httpClientProvider.overrideWithValue(
      MockClient((http.Request request) async => api.handle(request)),
    ),
    secureStoreProvider.overrideWithValue(InMemorySecureStore()),
  ],
  child: const MaterialApp(home: BookingsScreen()),
);

void main() {
  testWidgets('pokazuje rezerwacje i sam doczytuje starsze', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakeHistoryApi api = FakeHistoryApi();

    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    // Pierwsza strona ma dwie rezerwacje, druga jedną — po doczytaniu widać
    // wszystkie trzy, bez ani jednego kliknięcia.
    expect(api.pages, <int>[1, 2]);
    expect(find.byType(Card), findsNWidgets(3));
    expect(find.text('Parasite'), findsNWidgets(3));
    expect(find.textContaining('Opłacona · 2 bilety'), findsOneWidget);
    expect(find.textContaining('Anulowana'), findsOneWidget);
    expect(find.textContaining('Wygasła'), findsOneWidget);
    expect(find.text('50,60 zł'), findsOneWidget);
  });

  testWidgets('pusta historia mówi, co się stanie po zakupie', timeout: limit, (
    WidgetTester tester,
  ) async {
    final FakeHistoryApi api = FakeHistoryApi(
      onList: (int page) => reply(emptyEnvelope()),
    );

    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Nie masz jeszcze żadnych rezerwacji'),
      findsOneWidget,
    );
    expect(find.byType(Card), findsNothing);
    // Jedno pytanie: pusta lista nie ma czego doczytywać.
    expect(api.pages, <int>[1]);
  });

  testWidgets(
    'nieudane doczytanie ZOSTAWIA listę i daje ponowienie',
    timeout: limit,
    (WidgetTester tester) async {
      // Decyzja 325 widziana z ekranu: dwie rezerwacje zostają na miejscu.
      final FakeHistoryApi api = FakeHistoryApi(
        onList: (int page) => page == 1
            ? reply(envelope('bookings_page'))
            : reply(<String, Object?>{
                'message': 'Za dużo żądań. Spróbuj za chwilę.',
                'code': 'TOO_MANY_REQUESTS',
              }, 429),
      );

      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      expect(find.byType(Card), findsNWidgets(2));
      expect(find.text('Za dużo żądań. Spróbuj za chwilę.'), findsOneWidget);
      expect(find.text('Pokaż starsze'), findsOneWidget);
      // Nie ma „Spróbuj ponownie” z AsyncView — czyli lista NIE wpadła w błąd.
      expect(find.text('Spróbuj ponownie'), findsNothing);
    },
  );

  testWidgets('ponowienie po błędzie doczytuje stronę', timeout: limit, (
    WidgetTester tester,
  ) async {
    int drugaProba = 0;
    final FakeHistoryApi api = FakeHistoryApi(
      onList: (int page) {
        if (page == 1) {
          return reply(envelope('bookings_page'));
        }
        drugaProba++;
        return drugaProba == 1
            ? reply(<String, Object?>{
                'message': 'Za dużo żądań.',
                'code': 'TOO_MANY_REQUESTS',
              }, 429)
            : reply(envelope('bookings_page2'));
      },
    );
    await tester.pumpWidget(screenWith(api));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Pokaż starsze'));
    await tester.pumpAndSettle();

    expect(find.byType(Card), findsNWidgets(3));
    expect(find.text('Pokaż starsze'), findsNothing);
  });

  testWidgets(
    'błąd pierwszej strony daje przycisk ponowienia',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeHistoryApi api = FakeHistoryApi(
        onList: (int page) => reply(<String, Object?>{
          'message': 'Wymagane jest zalogowanie.',
          'code': 'UNAUTHENTICATED',
        }, 401),
      );

      await tester.pumpWidget(screenWith(api));
      await tester.pumpAndSettle();

      expect(find.text('Wymagane jest zalogowanie.'), findsOneWidget);
      expect(find.text('Spróbuj ponownie'), findsOneWidget);
      expect(find.byType(Card), findsNothing);
    },
  );
}
