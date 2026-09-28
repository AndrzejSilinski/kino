// Stan płatności: rozpoczęcie, powrót do przerwanej, rezygnacja i — rzecz
// najważniejsza — CZEKANIE NA SERWER.
//
// Decyzja 313: o tym, czy klient zapłacił, NIE rozstrzyga wynik z telefonu.
// PaymentSheet potrafi wrócić z sukcesem, zanim pieniądze zostaną rozliczone,
// bo rezerwację oznacza jako opłaconą dopiero webhook Stripe'a docierający do
// naszego serwera. Aplikacja, która na podstawie własnego wyniku pokaże
// „kupione”, będzie czasem kłamać — i to akurat w tę stronę, która boli
// najbardziej. Dlatego po powrocie z płatności pytamy serwer o rezerwację,
// aż przestanie być `pending`.
//
// Okno odpytywania jest parametrem (`checkoutPollingProvider`), a nie stałą:
// w testach ma trwać milisekundy, a nie pół minuty — nauczka z bloku G1.

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/data/checkout_repository.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/checkout.dart';
import 'package:cinema/state/booking.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<CheckoutRepository> checkoutRepositoryProvider =
    Provider<CheckoutRepository>(
      (Ref ref) => CheckoutRepository(ref.watch(apiClientProvider)),
    );

/// Jak długo i jak często pytamy serwer o potwierdzenie zapłaty.
class CheckoutPolling {
  const CheckoutPolling({
    this.interval = const Duration(seconds: 2),
    this.attempts = 15,
  });

  final Duration interval;

  /// Razem z odstępem daje domyślnie 30 sekund czekania — tyle, ile zwykle
  /// zajmuje webhook. Po tym czasie nie mówimy „nie zapłacono”, tylko
  /// „potwierdzenie jeszcze nie dotarło”, bo to dwie różne rzeczy.
  final int attempts;
}

final Provider<CheckoutPolling> checkoutPollingProvider =
    Provider<CheckoutPolling>((Ref ref) => const CheckoutPolling());

class CheckoutState {
  const CheckoutState({
    this.checkout,
    this.booking,
    this.notice,
    this.conflictReference,
    this.busy = false,
    this.waiting = false,
  });

  /// Rozpoczęta płatność wraz z danymi dla Stripe'a.
  final Checkout? checkout;

  /// Ostatni znany stan rezerwacji z serwera.
  final Booking? booking;

  /// Komunikat dla użytkownika — ten sam kształt co przy wyborze miejsc.
  final SelectionNotice? notice;

  /// Numer rezerwacji z błędu 409: klient ma wrócić do TAMTEJ płatności.
  final String? conflictReference;

  final bool busy;

  /// Czekamy na potwierdzenie zapłaty z serwera.
  final bool waiting;

  bool get isPaid => booking?.status.isPaid ?? false;

  bool get canPay => checkout != null && !busy;

  CheckoutState copyWith({
    Checkout? checkout,
    Booking? booking,
    SelectionNotice? notice,
    String? conflictReference,
    bool? busy,
    bool? waiting,
    bool clearCheckout = false,
    bool clearNotice = false,
    bool clearConflict = false,
  }) => CheckoutState(
    checkout: clearCheckout ? null : (checkout ?? this.checkout),
    booking: booking ?? this.booking,
    notice: clearNotice ? null : (notice ?? this.notice),
    conflictReference: clearConflict
        ? null
        : (conflictReference ?? this.conflictReference),
    busy: busy ?? this.busy,
    waiting: waiting ?? this.waiting,
  );
}

class CheckoutController extends AsyncNotifier<CheckoutState> {
  CheckoutController(this.screeningId);

  final int screeningId;

  CheckoutRepository get _repo => ref.read(checkoutRepositoryProvider);

  @override
  Future<CheckoutState> build() async {
    // Wejście na ekran NIE tworzy płatności. Utworzenie intencji w Stripe to
    // skutek świadomego kliknięcia, nie skutek obejrzenia ekranu (decyzja 314).
    return const CheckoutState();
  }

  /// Rozpoczyna płatność albo wraca do rozpoczętej.
  ///
  /// Serwer sam rozróżnia te przypadki: 201 dla nowej, 200 dla powrotu do tej
  /// samej (potwierdzone rozpoznaniem), więc aplikacja nie musi niczego
  /// pamiętać między uruchomieniami.
  Future<void> start() async {
    final CheckoutState? current = state.value;
    if (current == null || current.busy) {
      return;
    }
    state = AsyncData<CheckoutState>(
      current.copyWith(busy: true, clearNotice: true, clearConflict: true),
    );
    try {
      final Checkout checkout = await _repo.start(screeningId);
      _update(
        (CheckoutState base) => base.copyWith(
          checkout: checkout,
          booking: checkout.booking,
          busy: false,
        ),
      );
    } on ApiError catch (error) {
      _onStartError(error);
    }
  }

  void _onStartError(ApiError error) {
    if (error.code == ApiError.bookingAlreadyPending) {
      // Koszyk zmienił się od czasu rozpoczęcia płatności. Serwer podaje numer
      // rezerwacji, do której trzeba wrócić — pokazujemy ją, zamiast zostawiać
      // klienta z samym komunikatem o błędzie.
      final String? reference = error.bookingReference;
      _update(
        (CheckoutState base) => base.copyWith(
          busy: false,
          conflictReference: reference,
          notice: SelectionNotice.fromError(error),
        ),
      );
      if (reference != null) {
        unawaited(refreshBooking(reference));
      }
      return;
    }
    _update(
      (CheckoutState base) =>
          base.copyWith(busy: false, notice: SelectionNotice.fromError(error)),
    );
  }

  /// Rezygnacja z płatności — miejsca wracają do sprzedaży od razu.
  Future<void> cancel() async {
    final CheckoutState? current = state.value;
    final String? reference =
        current?.checkout?.booking.reference ?? current?.conflictReference;
    if (current == null || current.busy || reference == null) {
      return;
    }
    state = AsyncData<CheckoutState>(
      current.copyWith(busy: true, clearNotice: true),
    );
    try {
      final Booking booking = await _repo.cancelPayment(reference);
      _update(
        (CheckoutState base) => base.copyWith(
          booking: booking,
          busy: false,
          clearCheckout: true,
          clearConflict: true,
        ),
      );
    } on ApiError catch (error) {
      _update(
        (CheckoutState base) => base.copyWith(
          busy: false,
          notice: SelectionNotice.fromError(error),
        ),
      );
    }
  }

  /// Pobiera aktualny stan rezerwacji.
  Future<Booking?> refreshBooking([String? reference]) async {
    final CheckoutState? current = state.value;
    final String? key =
        reference ??
        current?.checkout?.booking.reference ??
        current?.booking?.reference;
    if (key == null) {
      return null;
    }
    try {
      final Booking booking = await _repo.booking(key);
      _update((CheckoutState base) => base.copyWith(booking: booking));
      return booking;
    } on ApiError catch (error) {
      _update(
        (CheckoutState base) =>
            base.copyWith(notice: SelectionNotice.fromError(error)),
      );
      return null;
    }
  }

  /// Czeka, aż SERWER potwierdzi zapłatę (decyzja 313).
  ///
  /// Wołane po powrocie z ekranu płatności. Kończy się, gdy rezerwacja
  /// przestanie być `pending` albo gdy skończy się okno odpytywania — i to
  /// drugie NIE znaczy „nie zapłacono”, tylko „potwierdzenie jeszcze nie
  /// dotarło”. Taki komunikat dostaje wtedy użytkownik.
  Future<Booking?> waitForPayment() async {
    final CheckoutState? current = state.value;
    final String? reference = current?.checkout?.booking.reference;
    if (current == null || reference == null) {
      return null;
    }
    final CheckoutPolling polling = ref.read(checkoutPollingProvider);
    state = AsyncData<CheckoutState>(
      current.copyWith(waiting: true, clearNotice: true),
    );
    Booking? last;
    for (int attempt = 0; attempt < polling.attempts; attempt++) {
      last = await refreshBooking(reference);
      if (last != null && !last.status.isPending) {
        _update((CheckoutState base) => base.copyWith(waiting: false));
        return last;
      }
      await Future<void>.delayed(polling.interval);
    }
    _update(
      (CheckoutState base) => base.copyWith(
        waiting: false,
        notice: const SelectionNotice(
          'Potwierdzenie płatności jeszcze nie dotarło. Rezerwacja jest '
          'widoczna w historii — odśwież ją za chwilę.',
        ),
      ),
    );
    return last;
  }

  void dismissNotice() {
    final CheckoutState? current = state.value;
    if (current?.notice == null) {
      return;
    }
    _update((CheckoutState base) => base.copyWith(clearNotice: true));
  }

  void _update(CheckoutState Function(CheckoutState base) change) {
    final CheckoutState? current = state.value;
    if (current == null) {
      return;
    }
    state = AsyncData<CheckoutState>(change(current));
  }
}

/// Typ providera zostawiamy wnioskowaniu (pułapka CV).
final checkoutProvider =
    AsyncNotifierProvider.family<CheckoutController, CheckoutState, int>(
      CheckoutController.new,
    );
