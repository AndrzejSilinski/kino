// Ekran jednej rezerwacji: bilety, kod QR i PDF.
//
// Obraz kodu w teście widgetów NIGDY się nie wczyta (pułapka CW), więc nie
// sprawdzamy pikseli — sprawdzamy, że widget obrazu istnieje, że ma właściwy
// adres i że niesie nagłówek z tokenem. To jest dokładnie to, co może się
// zepsuć: adres i nagłówek.

import 'dart:convert';

import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/features/bookings/booking_screen.dart';
import 'package:cinema/state/bookings.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fake_file_share.dart';
import '../fixtures/fixtures.dart';

const Timeout limit = Timeout(Duration(seconds: 30));
const String reference = '01M2R0FF9TF8JNNQ9GBNZJ3TDQ';
const String token = '1|tokenTestowyEkranu';

class FakeBookingApi {
  FakeBookingApi({this.onDetails, this.onPdf});

  final http.Response Function()? onDetails;
  final http.Response Function()? onPdf;

  int detailCalls = 0;
  int pdfCalls = 0;
  final List<Map<String, String>> pdfHeaders = <Map<String, String>>[];

  http.Response handle(http.Request request) {
    if (request.url.path.endsWith('/tickets/pdf')) {
      pdfCalls++;
      pdfHeaders.add(request.headers);
      return onPdf?.call() ??
          http.Response(
            '%PDF-1.4 udawany plik',
            200,
            headers: <String, String>{'content-type': 'application/pdf'},
          );
    }
    detailCalls++;
    return onDetails?.call() ?? reply(envelope('booking_tickets'));
  }
}

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

Future<Widget> screenWith(FakeBookingApi api, {FakeFileShare? share}) async {
  final AppSession session = AppSession(InMemorySecureStore());
  await session.setToken(token);
  return ProviderScope(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(InMemorySecureStore()),
      sessionProvider.overrideWithValue(session),
      fileShareProvider.overrideWithValue(share ?? FakeFileShare()),
    ],
    child: const MaterialApp(home: BookingScreen(reference: reference)),
  );
}

Image imageAt(WidgetTester tester, int index) =>
    tester.widgetList<Image>(find.byType(Image)).elementAt(index);

/// Ekran na WYSOKIM widoku, żeby oba bilety weszły do drzewa.
///
/// Domyślne płótno testu widgetów ma 800×600, a jeden bilet z kodem QR zajmuje
/// ponad 350 pikseli — drugi bilet leżał więc poza widokiem i `ListView` go
/// nie montował, czyli `find.text` słusznie go nie widział (pułapka DX).
/// Przewijanie w każdym teście byłoby hałasem; wyższe płótno mówi wprost,
/// o co chodzi: chcę widzieć CAŁY ekran.
void tallView(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 1800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('pokazuje seans, status i bilety', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);

    await tester.pumpWidget(await screenWith(FakeBookingApi()));
    await tester.pumpAndSettle();

    expect(find.text('Parasite'), findsOneWidget);
    expect(find.textContaining('Sala A · Kino Bałtyk'), findsOneWidget);
    expect(find.textContaining('ul. Długa 1, Gdańsk'), findsOneWidget);
    expect(find.text('Opłacona'), findsOneWidget);
    expect(find.text('Numer $reference'), findsOneWidget);
    expect(find.text('Zapłacono 50,60 zł'), findsOneWidget);
    expect(find.text('Bilety (2)'), findsOneWidget);
    expect(find.text('Miejsce A3'), findsOneWidget);
    expect(find.text('Miejsce A4'), findsOneWidget);
  });

  testWidgets(
    'kod QR idzie z NAGŁÓWKIEM tokenu i tylko dla ważnego biletu',
    timeout: limit,
    (WidgetTester tester) async {
      tallView(tester);

      await tester.pumpWidget(await screenWith(FakeBookingApi()));
      await tester.pumpAndSettle();

      // Dwa bilety, ale kod ma tylko jeden: drugi jest już wykorzystany.
      expect(find.byType(Image), findsOneWidget);
      final NetworkImage obraz = imageAt(tester, 0).image as NetworkImage;
      expect(obraz.url, endsWith('/tickets/9/qr'));
      expect(obraz.headers?['Authorization'], 'Bearer $token');
      expect(find.text('Ważny'), findsOneWidget);
      expect(find.text('Wykorzystany'), findsOneWidget);
      expect(find.textContaining('Zeskanowany'), findsOneWidget);
      expect(find.textContaining('Pokaż ten kod przy wejściu'), findsOneWidget);
    },
  );

  testWidgets('PDF idzie do systemu z nazwą i treścią', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeBookingApi api = FakeBookingApi();
    final FakeFileShare share = FakeFileShare();
    await tester.pumpWidget(await screenWith(api, share: share));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Bilety w PDF'));
    await tester.pumpAndSettle();

    expect(api.pdfCalls, 1);
    // Plik pobiera APLIKACJA, z tokenem — adres bez tokenu oddaje 401.
    expect(api.pdfHeaders.single['Authorization'], 'Bearer $token');
    expect(share.calls, 1);
    expect(share.filename, 'bilety-$reference.pdf');
    expect(share.mime, 'application/pdf');
    expect(utf8.decode(share.bytes), startsWith('%PDF'));
  });

  testWidgets('nieudane pobranie PDF-a nie gasi ekranu', timeout: limit, (
    WidgetTester tester,
  ) async {
    tallView(tester);
    final FakeFileShare share = FakeFileShare();
    final FakeBookingApi api = FakeBookingApi(
      onPdf: () => reply(<String, Object?>{
        'message': 'Za dużo żądań. Spróbuj za chwilę.',
        'code': 'TOO_MANY_REQUESTS',
      }, 429),
    );
    await tester.pumpWidget(await screenWith(api, share: share));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Bilety w PDF'));
    await tester.pumpAndSettle();

    expect(share.calls, 0);
    expect(find.textContaining('Nie udało się pobrać PDF-a'), findsOneWidget);
    // Bilety dalej są na ekranie — to one są tu najważniejsze, nie PDF.
    expect(find.text('Miejsce A3'), findsOneWidget);
  });

  testWidgets(
    'błąd pobrania rezerwacji daje przycisk ponowienia',
    timeout: limit,
    (WidgetTester tester) async {
      final FakeBookingApi api = FakeBookingApi(
        onDetails: () => reply(<String, Object?>{
          'message': 'Nie znaleziono zasobu.',
          'code': 'RESOURCE_NOT_FOUND',
        }, 404),
      );

      await tester.pumpWidget(await screenWith(api));
      await tester.pumpAndSettle();

      expect(find.text('Nie znaleziono zasobu.'), findsOneWidget);
      expect(find.text('Spróbuj ponownie'), findsOneWidget);
      expect(find.byType(Image), findsNothing);
    },
  );
}
