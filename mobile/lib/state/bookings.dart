// Stan historii zakupów: lista z doczytywaniem i szczegóły jednej rezerwacji.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/file_share.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/data/bookings_repository.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<BookingsRepository> bookingsRepositoryProvider =
    Provider<BookingsRepository>(
      (Ref ref) => BookingsRepository(ref.watch(apiClientProvider)),
    );

/// Nagłówki do pobrania obrazu kodu QR (decyzja 324).
///
/// Osobna klasa, a nie gotowa mapa w providerze, i to jest tu istotne: token
/// żyje w [AppSession], czyli w obiekcie MUTOWALNYM, o którego zmianie Riverpod
/// nie wie. Provider zwracający gotową mapę zapamiętałby ją raz i po ponownym
/// zalogowaniu podawałby nagłówek ze starym tokenem — a objawiłoby się to
/// pustym prostokątem w miejscu kodu QR, czyli w najgorszym momencie, przy
/// wejściu na salę. Tu nagłówki liczą się przy KAŻDYM pobraniu obrazu.
class TicketImageHeaders {
  const TicketImageHeaders(this._session);

  final ApiSession _session;

  Map<String, String> headers() {
    final String? token = _session.token;
    return token == null || token.isEmpty
        ? const <String, String>{}
        : <String, String>{'Authorization': 'Bearer $token'};
  }
}

final Provider<TicketImageHeaders> ticketImageHeadersProvider =
    Provider<TicketImageHeaders>(
      (Ref ref) => TicketImageHeaders(ref.watch(sessionProvider)),
    );

class BookingHistory extends AsyncNotifier<Paginated<Booking>> {
  bool _loading = false;

  @override
  Future<Paginated<Booking>> build() =>
      ref.read(bookingsRepositoryProvider).list();

  /// Doczytuje następną stronę.
  ///
  /// Zwraca błąd, zamiast wstawiać go do stanu — i to jest cała decyzja 325.
  /// `AsyncError` skasowałby listę, którą użytkownik właśnie czyta: jedno
  /// nieudane doczytanie na końcu przewijania zamieniłoby dwadzieścia
  /// widocznych rezerwacji w komunikat o błędzie. Ekran pokazuje ten błąd
  /// paskiem u dołu i zostawia listę tam, gdzie była.
  Future<ApiError?> loadMore() async {
    final Paginated<Booking>? current = state.value;
    if (current == null || !current.hasMore || _loading) {
      return null;
    }
    _loading = true;
    try {
      final Paginated<Booking> next = await ref
          .read(bookingsRepositoryProvider)
          .list(page: current.currentPage + 1);
      state = AsyncData<Paginated<Booking>>(current.followedBy(next));
      return null;
    } on ApiError catch (error) {
      return error;
    } finally {
      _loading = false;
    }
  }

  /// Odświeżenie z góry — po zakupie albo po pociągnięciu listy w dół.
  Future<void> reload() async {
    state = AsyncData<Paginated<Booking>>(
      await ref.read(bookingsRepositoryProvider).list(),
    );
  }
}

final AsyncNotifierProvider<BookingHistory, Paginated<Booking>>
bookingHistoryProvider =
    AsyncNotifierProvider<BookingHistory, Paginated<Booking>>(
      BookingHistory.new,
    );

/// Udostępnianie pliku przez system. Podmieniane w testach na atrapę, bo
/// prawdziwe wymaga platformy natywnej (decyzja 327 — ta sama zasada co 315).
final Provider<FileShare> fileShareProvider = Provider<FileShare>(
  (Ref ref) => const SystemFileShare(),
);

/// Pobranie PDF-a z biletami i podanie go systemowi.
///
/// Stan to jedna flaga „trwa pobieranie”: PDF z rozpoznania waży 42 kB, więc
/// w kiepskiej sieci trwa to chwilę, a podwójne stuknięcie zużyłoby dwa
/// żądania z limitu 30 na minutę, wspólnego z obrazami kodów QR.
class TicketsPdf extends Notifier<bool> {
  @override
  bool build() => false;

  Future<ApiError?> share(String reference) async {
    if (state) {
      return null;
    }
    state = true;
    try {
      final List<int> bytes = await ref
          .read(bookingsRepositoryProvider)
          .ticketsPdf(reference);
      await ref
          .read(fileShareProvider)
          .shareBytes(
            // Ta sama nazwa, jaką podaje serwer w Content-Disposition — żeby plik
            // zapisany z telefonu nazywał się tak samo jak pobrany z przeglądarki.
            filename: 'bilety-$reference.pdf',
            bytes: bytes,
            mime: 'application/pdf',
          );
      return null;
    } on ApiError catch (error) {
      return error;
    } finally {
      state = false;
    }
  }
}

final NotifierProvider<TicketsPdf, bool> ticketsPdfProvider =
    NotifierProvider<TicketsPdf, bool>(TicketsPdf.new);

/// Szczegóły jednej rezerwacji. Rodzina po `reference`, bo to ono jest kluczem
/// trasy — tak samo jak w SPA i w adresach z powiadomień.
final bookingDetailsProvider = FutureProvider.family<Booking, String>(
  (Ref ref, String reference) =>
      ref.watch(bookingsRepositoryProvider).details(reference),
);
