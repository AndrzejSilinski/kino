// Stan historii zakupów: doczytywanie stron i nagłówki do obrazu kodu QR.

import 'dart:convert';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/secure_store.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';
import 'package:cinema/state/bookings.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import '../fixtures/fixtures.dart';

/// Atrapa serwera historii: oddaje stronę zależnie od parametru `page`.
class FakeHistoryApi {
  FakeHistoryApi({this.onList});

  /// Podmiana odpowiedzi dla WSKAZANEJ strony.
  final http.Response Function(int page)? onList;

  int listCalls = 0;
  int detailCalls = 0;
  final List<int> pages = <int>[];

  http.Response handle(http.Request request) {
    if (request.url.path.endsWith('/bookings')) {
      listCalls++;
      final int page = int.parse(request.url.queryParameters['page'] ?? '1');
      pages.add(page);
      return onList?.call(page) ??
          reply(envelope(page == 1 ? 'bookings_page' : 'bookings_page2'));
    }
    detailCalls++;
    return reply(envelope('booking_tickets'));
  }
}

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

ProviderContainer containerFor(FakeHistoryApi api, {SecureStore? store}) {
  final ProviderContainer container = ProviderContainer(
    retry: noRetry,
    overrides: [
      httpClientProvider.overrideWithValue(
        MockClient((http.Request request) async => api.handle(request)),
      ),
      secureStoreProvider.overrideWithValue(store ?? InMemorySecureStore()),
    ],
  );
  addTearDown(container.dispose);
  return container;
}

Paginated<Booking> history(ProviderContainer container) =>
    container.read(bookingHistoryProvider).requireValue;

void main() {
  test('pierwsza strona historii przychodzi od najnowszej', () async {
    final FakeHistoryApi api = FakeHistoryApi();
    final ProviderContainer container = containerFor(api);

    await container.read(bookingHistoryProvider.future);

    expect(api.pages, <int>[1]);
    expect(history(container).items, hasLength(2));
    expect(history(container).total, 3);
    expect(history(container).hasMore, isTrue);
  });

  test('doczytanie dokłada stronę, nie podmienia listy', () async {
    final FakeHistoryApi api = FakeHistoryApi();
    final ProviderContainer container = containerFor(api);
    await container.read(bookingHistoryProvider.future);

    final ApiError? error = await container
        .read(bookingHistoryProvider.notifier)
        .loadMore();

    expect(error, isNull);
    expect(api.pages, <int>[1, 2]);
    expect(history(container).items, hasLength(3));
    expect(history(container).hasMore, isFalse);
  });

  test('na ostatniej stronie doczytywanie NIE pyta serwera', () async {
    final FakeHistoryApi api = FakeHistoryApi();
    final ProviderContainer container = containerFor(api);
    await container.read(bookingHistoryProvider.future);
    await container.read(bookingHistoryProvider.notifier).loadMore();

    await container.read(bookingHistoryProvider.notifier).loadMore();

    // Dwa żądania, nie trzy: przewijanie na koniec listy nie ma prawa
    // dobijać się do serwera po stronę, której nie ma.
    expect(api.pages, <int>[1, 2]);
  });

  test('nieudane doczytanie NIE kasuje tego, co widać', () async {
    // Decyzja 325: awaria przy końcu przewijania nie może zamienić listy
    // rezerwacji w komunikat o błędzie.
    final FakeHistoryApi api = FakeHistoryApi(
      onList: (int page) => page == 1
          ? reply(envelope('bookings_page'))
          : reply(<String, Object?>{
              'message': 'Za dużo żądań.',
              'code': 'TOO_MANY_REQUESTS',
            }, 429),
    );
    final ProviderContainer container = containerFor(api);
    await container.read(bookingHistoryProvider.future);

    final ApiError? error = await container
        .read(bookingHistoryProvider.notifier)
        .loadMore();

    expect(error, isNotNull);
    expect(error!.code, 'TOO_MANY_REQUESTS');
    expect(container.read(bookingHistoryProvider).hasError, isFalse);
    expect(history(container).items, hasLength(2));
    expect(history(container).hasMore, isTrue);
  });

  test('odświeżenie wraca na pierwszą stronę', () async {
    final FakeHistoryApi api = FakeHistoryApi();
    final ProviderContainer container = containerFor(api);
    await container.read(bookingHistoryProvider.future);
    await container.read(bookingHistoryProvider.notifier).loadMore();
    expect(history(container).items, hasLength(3));

    await container.read(bookingHistoryProvider.notifier).reload();

    expect(api.pages, <int>[1, 2, 1]);
    expect(history(container).items, hasLength(2));
    expect(history(container).currentPage, 1);
  });

  test('szczegóły rezerwacji niosą bilety', () async {
    final FakeHistoryApi api = FakeHistoryApi();
    final ProviderContainer container = containerFor(api);

    final Booking booking = await container.read(
      bookingDetailsProvider('01M2R0FF9TF8JNNQ9GBNZJ3TDQ').future,
    );

    expect(api.detailCalls, 1);
    expect(booking.tickets, hasLength(2));
    expect(booking.validTickets, hasLength(1));
  });

  test('nagłówki obrazu kodu QR niosą AKTUALNY token', () async {
    final InMemorySecureStore store = InMemorySecureStore();
    final ProviderContainer container = containerFor(
      FakeHistoryApi(),
      store: store,
    );
    final AppSession session = container.read(sessionProvider);
    final TicketImageHeaders headers = container.read(
      ticketImageHeadersProvider,
    );
    expect(headers.headers(), isEmpty);

    await session.setToken('1|pierwszyTokenTestowy');
    expect(headers.headers()['Authorization'], 'Bearer 1|pierwszyTokenTestowy');

    // Sedno decyzji 324: po ponownym zalogowaniu nagłówek musi mieć NOWY
    // token. Gdyby provider zapamiętał gotową mapę, klient zobaczyłby pusty
    // prostokąt w miejscu kodu QR — przy bramce na salę.
    await session.setToken('2|drugiTokenTestowy');
    expect(headers.headers()['Authorization'], 'Bearer 2|drugiTokenTestowy');
  });
}
