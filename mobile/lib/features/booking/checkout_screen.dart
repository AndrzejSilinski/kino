// Ekran podsumowania i płatności — ostatni krok ścieżki zakupowej.
//
// Cztery rzeczy, których nie widać w kodzie na pierwszy rzut oka:
//
// 1. WEJŚCIE NA TEN EKRAN NIE TWORZY PŁATNOŚCI (decyzja 314). Najpierw
//    podsumowanie z koszyka i przycisk, a intencja w Stripe powstaje dopiero po
//    świadomym kliknięciu. Inaczej cofnięcie się i wejście ponownie zamieniałoby
//    koszyk w rezerwację i blokowało miejsca komuś, kto by je kupił.
// 2. LICZNIK OKNA PŁATNOŚCI liczy się od `expires_in_seconds` Z SERWERA, a nie
//    od różnicy dat na telefonie (decyzja 288): zegar urządzenia bywa
//    przestawiony. Dojście do zera pobiera rezerwację od nowa, więc klient
//    zobaczy prawdę („Wygasła”), a nie zamrożony licznik.
// 3. EKRAN JEST OBSERWATOREM KANAŁU rezerwacji (`_BookingChannel`), tak samo jak
//    ekran planu sali jest obserwatorem kanału seansu. Ramka tylko wyzwala
//    pytanie do API (decyzja 317), a odpytywanie zostaje drogą pewną (318).
// 4. Po powrocie z arkusza NIE mówimy „kupione”, dopóki nie powie tego serwer
//    (decyzja 313). Dlatego jest tu osobny stan „czekamy na potwierdzenie”,
//    a jego zakończenie bez potwierdzenia nie jest komunikatem o odmowie.

import 'dart:async';

import 'package:cinema/core/realtime.dart';
import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/features/common/countdown.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/booking_event.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/models/checkout.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/booking.dart';
import 'package:cinema/state/checkout.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class CheckoutScreen extends ConsumerWidget {
  const CheckoutScreen({required this.screeningId, super.key});

  final int screeningId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<CheckoutState> value = ref.watch(
      checkoutProvider(screeningId),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Podsumowanie i płatność')),
      body: AsyncView<CheckoutState>(
        value: value,
        onRetry: () => ref.invalidate(checkoutProvider(screeningId)),
        builder: (CheckoutState state) {
          final CheckoutController controller = ref.read(
            checkoutProvider(screeningId).notifier,
          );
          final Widget body = _Body(
            screeningId: screeningId,
            state: state,
            controller: controller,
          );
          // Kanał subskrybujemy dopiero, gdy znamy numer rezerwacji — przed
          // odpowiedzią checkoutu nie ma jak nazwać kanału (pułapka DS).
          final String? reference =
              state.checkout?.booking.reference ??
              state.conflictReference ??
              state.booking?.reference;
          if (reference == null) {
            return body;
          }
          return _BookingChannel(
            reference: reference,
            controller: controller,
            child: body,
          );
        },
      ),
    );
  }
}

/// Nasłuch kanału rezerwacji. Osobny widget, bo `ref.listen` ma być bezwarunkowe
/// w swoim `build`, a warunkowe jest samo zamontowanie tego widgetu.
class _BookingChannel extends ConsumerWidget {
  const _BookingChannel({
    required this.reference,
    required this.controller,
    required this.child,
  });

  final String reference;
  final CheckoutController controller;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen(bookingEventsProvider(reference), (
      AsyncValue<RealtimeEvent>? previous,
      AsyncValue<RealtimeEvent> next,
    ) {
      final RealtimeEvent? event = next.value;
      if (event == null) {
        return;
      }
      final BookingStatusEvent? parsed = BookingStatusEvent.tryParse(
        event.data,
      );
      if (parsed != null) {
        unawaited(controller.onBookingEvent(parsed));
      }
    });
    return child;
  }
}

class _Body extends StatelessWidget {
  const _Body({
    required this.screeningId,
    required this.state,
    required this.controller,
  });

  final int screeningId;
  final CheckoutState state;
  final CheckoutController controller;

  @override
  Widget build(BuildContext context) {
    final Booking? booking = state.booking;
    if (booking != null && booking.status.isPaid) {
      return _Paid(booking: booking);
    }
    if (booking != null && booking.status.isClosed) {
      return _Closed(booking: booking, screeningId: screeningId);
    }

    return Column(
      children: <Widget>[
        if (state.notice case final SelectionNotice notice)
          _Notice(notice: notice, onClose: controller.dismissNotice),
        if (state.conflictReference case final String reference)
          _Conflict(reference: reference),
        Expanded(
          child: switch (state.checkout) {
            final Checkout checkout => _Started(
              checkout: checkout,
              onExpired: () => unawaited(controller.refreshBooking()),
            ),
            null => _Summary(screeningId: screeningId),
          },
        ),
        _Actions(state: state, controller: controller),
      ],
    );
  }
}

/// Podsumowanie z KOSZYKA — zanim rezerwacja w ogóle powstanie.
class _Summary extends ConsumerWidget {
  const _Summary({required this.screeningId});

  final int screeningId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ThemeData theme = Theme.of(context);
    return AsyncView<Cart>(
      value: ref.watch(cartProvider(screeningId)),
      onRetry: () => ref.invalidate(cartProvider(screeningId)),
      builder: (Cart cart) => ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          Text('Do zapłaty', style: theme.textTheme.titleMedium),
          Text(cart.total.formatted, style: theme.textTheme.headlineMedium),
          const SizedBox(height: 16),
          for (final CartSeat seat in cart.seats) _SeatRow(seat: seat),
          if (cart.expiresInSeconds case final int seconds) ...<Widget>[
            const Divider(),
            Row(
              children: <Widget>[
                const Icon(Icons.timer_outlined, size: 16),
                const SizedBox(width: 6),
                const Expanded(
                  child: Text('Miejsca zablokowane jeszcze przez'),
                ),
                Countdown(seconds: seconds, style: theme.textTheme.titleMedium),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// Jedno miejsce w podsumowaniu.
///
/// Osobny widget, a nie `ListTile` w pętli, wyłącznie po to, żeby dało się
/// przypisać nazwę kategorii do zmiennej: w kontrakcie jest OPCJONALNA
/// (pułapka DU), a `Text` wymaga napisu, nie napisu-albo-null.
class _SeatRow extends StatelessWidget {
  const _SeatRow({required this.seat});

  final CartSeat seat;

  @override
  Widget build(BuildContext context) {
    final String? category = seat.category.name;
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.event_seat_outlined),
      title: Text('Miejsce ${seat.label}'),
      subtitle: category == null ? null : Text(category),
      trailing: Text(seat.price.formatted),
    );
  }
}

/// Rozpoczęta płatność: kwota, numer rezerwacji i okno na zapłacenie.
class _Started extends StatelessWidget {
  const _Started({required this.checkout, required this.onExpired});

  final Checkout checkout;

  /// Okno się zamknęło — pobieramy rezerwację, żeby pokazać prawdę.
  final VoidCallback onExpired;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        Text('Do zapłaty', style: theme.textTheme.titleMedium),
        Text(
          checkout.booking.total.formatted,
          style: theme.textTheme.headlineMedium,
        ),
        const SizedBox(height: 8),
        Text(
          'Rezerwacja ${checkout.booking.reference}',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        Row(
          children: <Widget>[
            const Icon(Icons.timer_outlined, size: 16),
            const SizedBox(width: 6),
            const Expanded(child: Text('Na zapłacenie zostało')),
            Countdown(
              seconds: checkout.payment.expiresInSeconds,
              onExpired: onExpired,
              style: theme.textTheme.titleMedium,
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          'Po tym czasie rezerwacja wygaśnie, a miejsca wrócą do sprzedaży.',
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }
}

class _Actions extends StatelessWidget {
  const _Actions({required this.state, required this.controller});

  final CheckoutState state;
  final CheckoutController controller;

  @override
  Widget build(BuildContext context) {
    if (state.waiting) {
      // Stan „zapłacone czy nie” rozstrzyga serwer, więc tu tylko czekamy —
      // i mówimy o tym wprost, zamiast pokazywać kręcące się kółko bez słowa.
      return const SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.all(16),
          child: Row(
            children: <Widget>[
              SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
              SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Czekamy na potwierdzenie płatności. Nie zamykaj aplikacji.',
                ),
              ),
            ],
          ),
        ),
      );
    }

    final bool busy = state.busy;
    final Checkout? checkout = state.checkout;
    final bool conflict = state.conflictReference != null;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: <Widget>[
            if (checkout != null)
              FilledButton.icon(
                onPressed: busy ? null : () => unawaited(controller.pay()),
                icon: const Icon(Icons.credit_card),
                label: Text('Zapłać ${checkout.booking.total.formatted}'),
              )
            else if (!conflict)
              // Przy konflikcie tego przycisku NIE ma: serwer odpowiedziałby
              // znów 409, bo płatność za te miejsca jest już rozpoczęta.
              // Jedyne sensowne wyjście to zrezygnować z tamtej.
              FilledButton(
                onPressed: busy ? null : () => unawaited(controller.start()),
                child: const Text('Przejdź do płatności'),
              ),
            if (checkout != null || conflict)
              TextButton(
                onPressed: busy ? null : () => unawaited(controller.cancel()),
                child: const Text('Zrezygnuj z płatności'),
              ),
          ],
        ),
      ),
    );
  }
}

class _Paid extends StatelessWidget {
  const _Paid({required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(
              Icons.check_circle_outline,
              size: 64,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(height: 16),
            // Etykieta Z SERWERA (decyzja 309) — ta sama, co w mailu i w panelu.
            Text(booking.statusLabel, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text('Rezerwacja ${booking.reference}'),
            Text(booking.total.formatted, style: theme.textTheme.titleMedium),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: () => context.go(Routes.home),
              child: const Text('Wróć do repertuaru'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Closed extends StatelessWidget {
  const _Closed({required this.booking, required this.screeningId});

  final Booking booking;
  final int screeningId;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.info_outline, size: 64),
            const SizedBox(height: 16),
            Text(booking.statusLabel, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'Miejsca wróciły do sprzedaży. Możesz wybrać je jeszcze raz, '
              'jeśli nikt nie był szybszy.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: () => context.go(Routes.seats(screeningId)),
              child: const Text('Wybierz miejsca ponownie'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Conflict extends StatelessWidget {
  const _Conflict({required this.reference});

  final String reference;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      color: colors.tertiaryContainer,
      padding: const EdgeInsets.all(12),
      child: Text(
        'Płatność za te miejsca jest już rozpoczęta pod numerem $reference. '
        'Zrezygnuj z niej, żeby zapłacić od nowa.',
        style: TextStyle(color: colors.onTertiaryContainer),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.notice, required this.onClose});

  final SelectionNotice notice;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      color: colors.errorContainer,
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              notice.message,
              style: TextStyle(color: colors.onErrorContainer),
            ),
          ),
          IconButton(
            tooltip: 'Zamknij',
            icon: const Icon(Icons.close, size: 18),
            onPressed: onClose,
          ),
        ],
      ),
    );
  }
}
