// Stan wyboru miejsc: plan sali, mój koszyk i to, co się właśnie dzieje.
//
// Jeden notifier na seans (rodzina providerów z argumentem `screeningId`).
// W Riverpodzie 3 rodzina klas przekazuje argument KONSTRUKTOREM, a `build()`
// jest bezargumentowe — sprawdzone w źródle wersji 3.4.3, nie w poradnikach
// do wersji 2 (decyzja 290, pułapka DB).
//
// Podział prawdy między dwa źródła:
//   - KOSZYK mówi, które miejsca są MOJE (serwer zwraca go przy każdej operacji),
//   - PLAN SALI mówi o wszystkich pozostałych.
// Dzięki temu po udanej blokadzie nie musimy poprawiać planu sali „na piechotę”:
// miejsce jest w koszyku, więc `statusOf` maluje je jako moje (decyzja 285).

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/realtime.dart';
import 'package:cinema/data/booking_repository.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/models/seat_event.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/state/providers.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<BookingRepository> bookingRepositoryProvider =
    Provider<BookingRepository>(
      (Ref ref) => BookingRepository(ref.watch(apiClientProvider)),
    );

/// Komunikat dla użytkownika po odrzuconym wyborze.
///
/// Jeden kształt dla komunikatów serwera (409, 422) i dla tych, które aplikacja
/// wie sama (limit miejsc, miejsce bez ceny) — ekran ma jedno miejsce, w którym
/// je pokazuje, a nie dwa różne kanały.
class SelectionNotice {
  const SelectionNotice(this.message, {this.code});

  factory SelectionNotice.fromError(ApiError error) =>
      SelectionNotice(error.message, code: error.code);

  final String message;

  /// Kod z serwera albo `null`, gdy komunikat powstał w aplikacji.
  final String? code;
}

class SeatSelectionState {
  const SeatSelectionState({
    required this.map,
    required this.cart,
    required this.maxSeats,
    this.busy = const <int>{},
    this.notice,
  });

  final SeatMap map;
  final Cart cart;

  /// Limit miejsc w jednej sesji — z `client-config`, nie z kodu aplikacji.
  final int maxSeats;

  /// Miejsca, na których trwa żądanie. Blokują powtórne kliknięcie i pozwalają
  /// pokazać kręcące się kółko dokładnie na jednym fotelu.
  final Set<int> busy;

  final SelectionNotice? notice;

  /// Status do narysowania. O MOICH miejscach rozstrzyga koszyk, o pozostałych
  /// plan sali.
  SeatStatus statusOf(Seat seat) {
    if (cart.seatIds.contains(seat.id)) {
      return SeatStatus.heldByYou;
    }
    // Plan sali z REST-a mógł zastać moją blokadę, której w koszyku już nie ma
    // — czyli właśnie ją zwolniłem. Wtedy miejsce jest wolne; bez tej gałęzi
    // odkliknięty fotel zostałby na ekranie „mój” do następnego pobrania planu.
    if (seat.status.isMine) {
      return SeatStatus.free;
    }
    return seat.status;
  }

  bool isBusy(Seat seat) => busy.contains(seat.id);

  bool get isFull => cart.seatsCount >= maxSeats;

  int get remaining => maxSeats - cart.seatsCount;

  /// Czy kliknięcie w to miejsce ma teraz sens.
  ///
  /// Wolne miejsce BEZ CENY też jest klikalne, choć blokada się nie uda.
  /// Powód jest z życia: fotel wygląda na wolny, więc klient w niego kliknie,
  /// a ekran, który milczy, wygląda jak zepsuty. `toggle` odpowiada wtedy
  /// komunikatem, nie żądaniem do serwera (decyzja 296).
  bool canTap(Seat seat) {
    if (isBusy(seat) || cart.pendingBooking != null) {
      return false;
    }
    final SeatStatus status = statusOf(seat);
    return status.isMine || status.isFree;
  }

  SeatSelectionState copyWith({
    SeatMap? map,
    Cart? cart,
    Set<int>? busy,
    SelectionNotice? notice,
    bool clearNotice = false,
  }) => SeatSelectionState(
    map: map ?? this.map,
    cart: cart ?? this.cart,
    maxSeats: maxSeats,
    busy: busy ?? this.busy,
    notice: clearNotice ? null : (notice ?? this.notice),
  );
}

class SeatSelection extends AsyncNotifier<SeatSelectionState> {
  SeatSelection(this.screeningId);

  final int screeningId;

  BookingRepository get _repo => ref.read(bookingRepositoryProvider);

  @override
  Future<SeatSelectionState> build() async {
    final ClientConfig config = await ref.watch(clientConfigProvider.future);
    // Plan sali PRZED koszykiem i to nie przypadek (decyzja 291): ta trasa nie
    // ma limitu zapytań i to ona wydaje sesję zakupową, gdy aplikacja jeszcze
    // jej nie ma. Odwrotna kolejność zużywałaby limit blokad (30/min) na samo
    // wydanie sesji.
    final SeatMap map = await _repo.seatMap(screeningId);
    final Cart cart = await _repo.cart(screeningId);

    // Zdarzenia z kanału seansu nakładamy na plan, zamiast pobierać go od nowa
    // przy każdej cudzej zmianie (decyzja 307). Subskrypcja żyje tyle, ile ten
    // provider, więc kanał odchodzi razem z ekranem.
    ref.listen(screeningEventsProvider(screeningId), (
      AsyncValue<RealtimeEvent>? previous,
      AsyncValue<RealtimeEvent> next,
    ) {
      final RealtimeEvent? event = next.value;
      if (event != null) {
        _onRealtimeEvent(event);
      }
    });

    // Po powrocie zerwanego łącza nie ma zaległych zdarzeń do nałożenia —
    // pobieramy pełny stan (wymóg 1.3 zadania, decyzja 302).
    ref.listen(realtimeResubscribedProvider, (
      AsyncValue<void>? previous,
      AsyncValue<void> next,
    ) {
      if (!next.isLoading && !next.hasError) {
        unawaited(reload());
      }
    });

    return SeatSelectionState(
      map: map,
      cart: cart,
      maxSeats: config.booking.maxSeatsPerSession,
    );
  }

  /// Nałożenie zdarzenia z kanału seansu.
  ///
  /// `seats.changed` niesie stan absolutny wymienionych miejsc — nakładamy go
  /// na plan, a identyfikatory z koszyka mają pierwszeństwo, bo broadcast opisuje
  /// MOJE miejsca jako `held` (decyzja 285). `seats.resync` to prośba serwera
  /// o pełne pobranie.
  void _onRealtimeEvent(RealtimeEvent raw) {
    final SeatSelectionState? current = state.value;
    final SeatsEvent? event = SeatsEvent.tryParse(raw);
    if (current == null || event == null || event.screeningId != screeningId) {
      return;
    }
    if (event.isResync) {
      unawaited(reload());
      return;
    }
    state = AsyncData<SeatSelectionState>(
      current.copyWith(
        map: current.map.applyChange(
          version: event.version,
          changes: event.changes,
          mine: current.cart.seatIds,
        ),
      ),
    );
  }

  /// Pełne pobranie stanu: plan sali i koszyk. Po przerwie w łączu ani jedno,
  /// ani drugie nie jest pewne — cudze blokady mogły wygasnąć, a moje zniknąć.
  Future<void> reload() async {
    final SeatSelectionState? current = state.value;
    if (current == null) {
      return;
    }
    final SeatMap map = await _repo.seatMap(screeningId);
    final Cart cart = await _repo.cart(screeningId);
    final SeatSelectionState base = state.value ?? current;
    state = AsyncData<SeatSelectionState>(base.copyWith(map: map, cart: cart));
  }

  /// Kliknięcie w fotel: wolny blokujemy, swój zwalniamy.
  Future<void> toggle(Seat seat) async {
    final SeatSelectionState? current = state.value;
    if (current == null || current.isBusy(seat)) {
      return;
    }
    if (current.cart.pendingBooking != null) {
      _say(
        const SelectionNotice(
          'Płatność za wybrane miejsca jest już rozpoczęta — '
          'dokończ ją albo z niej zrezygnuj.',
        ),
      );
      return;
    }
    final SeatStatus status = current.statusOf(seat);
    if (status.isMine) {
      await _run(<int>{seat.id}, () => _repo.releaseSeat(screeningId, seat.id));
      return;
    }
    if (!status.isFree) {
      return;
    }
    if (seat.price == null) {
      // Serwer odmówiłby blokady, bo kategoria miejsca nie ma ceny w cenniku
      // tego seansu. Mówimy to od razu, zamiast zużywać limit na pewne 422.
      _say(
        SelectionNotice(
          'Miejsce ${seat.label} nie ma ceny w cenniku tego seansu '
          'i nie można go kupić.',
        ),
      );
      return;
    }
    if (current.isFull) {
      _say(
        SelectionNotice(
          'W jednym koszyku można mieć najwyżej ${current.maxSeats} miejsc.',
        ),
      );
      return;
    }
    await _run(<int>{seat.id}, () => _repo.lock(screeningId, <int>[seat.id]));
  }

  /// „Wyczyść wybór” — porzucenie całego koszyka.
  Future<void> clear() async {
    final SeatSelectionState? current = state.value;
    if (current == null || current.cart.isEmpty) {
      return;
    }
    await _run(current.cart.seatIds, () => _repo.releaseAll(screeningId));
  }

  /// Ponowne pobranie planu sali. Wołane po błędzie mówiącym, że nasz plan jest
  /// nieaktualny, i przy pociągnięciu listy w dół.
  Future<void> refreshMap() async {
    final SeatSelectionState? current = state.value;
    if (current == null) {
      return;
    }
    final SeatMap map = await _repo.seatMap(screeningId);
    final SeatSelectionState base = state.value ?? current;
    state = AsyncData<SeatSelectionState>(base.copyWith(map: map));
  }

  void dismissNotice() {
    final SeatSelectionState? current = state.value;
    if (current == null || current.notice == null) {
      return;
    }
    state = AsyncData<SeatSelectionState>(current.copyWith(clearNotice: true));
  }

  void _say(SelectionNotice notice) {
    final SeatSelectionState? current = state.value;
    if (current == null) {
      return;
    }
    state = AsyncData<SeatSelectionState>(current.copyWith(notice: notice));
  }

  /// Jedna droga dla wszystkich operacji na blokadach.
  ///
  /// Koszyka nie zgadujemy: podnosimy tylko znacznik „w trakcie”, a stan
  /// zastępujemy tym, co zwrócił serwer. Przy 409 dodatkowo malujemy odrzucone
  /// fotele jako zajęte na podstawie `context.seat_ids` — po to serwer je
  /// przysyła, żeby nie trzeba było pobierać całego planu sali.
  Future<void> _run(Set<int> ids, Future<Cart> Function() action) async {
    final SeatSelectionState? start = state.value;
    if (start == null) {
      return;
    }
    state = AsyncData<SeatSelectionState>(
      start.copyWith(busy: <int>{...start.busy, ...ids}, clearNotice: true),
    );
    try {
      final Cart cart = await action();
      final SeatSelectionState base = state.value ?? start;
      state = AsyncData<SeatSelectionState>(
        base.copyWith(cart: cart, busy: base.busy.difference(ids)),
      );
    } on ApiError catch (error) {
      final SeatSelectionState base = state.value ?? start;
      state = AsyncData<SeatSelectionState>(
        base.copyWith(
          map: error.code == ApiError.seatsUnavailable
              ? base.map.markTaken(error.seatIds)
              : base.map,
          busy: base.busy.difference(ids),
          notice: SelectionNotice.fromError(error),
        ),
      );
      if (error.code == ApiError.seatsNotInHall) {
        // Serwer nie zna tego miejsca w tej sali, czyli nasz plan jest
        // nieaktualny. Odświeżenie jest tu jedyną sensowną reakcją.
        await refreshMap();
      }
    }
  }
}

/// Typ providera zostawiamy wnioskowaniu (pułapka CV).
final seatSelectionProvider =
    AsyncNotifierProvider.family<SeatSelection, SeatSelectionState, int>(
      SeatSelection.new,
    );
