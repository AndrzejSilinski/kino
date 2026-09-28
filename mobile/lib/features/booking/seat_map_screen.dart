// Ekran wyboru miejsc — serce ścieżki zakupowej.
//
// Trzy rzeczy, których nie widać w kodzie na pierwszy rzut oka:
//
// 1. Plan sali jest skalowalny (`InteractiveViewer`). Sala na 300 miejsc nie
//    zmieści się czytelnie na telefonie: albo fotele są za małe do kliknięcia,
//    albo plan wychodzi za ekran. Powiększanie dwoma palcami i podwójne
//    stuknięcie rozwiązują to bez własnej matematyki (decyzja 293).
// 2. Komunikat po odrzuconym wyborze pokazujemy jako pasek NAD planem, nie
//    jako „snackbar”: przy 409 użytkownik ma równocześnie przeczytać komunikat
//    i zobaczyć, że fotel zmienił kolor na zajęty (decyzja 294).
// 3. Wygaśnięcie blokady pobiera stan od nowa. Licznik dochodzący do zera bez
//    odświeżenia zostawiłby na ekranie koszyk, którego serwer już nie ma.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/features/booking/cart_bar.dart';
import 'package:cinema/features/booking/realtime_badge.dart';
import 'package:cinema/features/booking/seat_grid.dart';
import 'package:cinema/features/booking/seat_legend.dart';
import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/booking.dart';
import 'package:cinema/state/realtime.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class SeatMapScreen extends ConsumerWidget {
  const SeatMapScreen({required this.screeningId, super.key});

  final int screeningId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<SeatSelectionState> value = ref.watch(
      seatSelectionProvider(screeningId),
    );
    // Stan połączenia przed pobraniem klienta to „łączę”: dopóki `client-config`
    // nie wróci, gniazda jeszcze nie ma, a nie jest to awaria.
    final RealtimeStatus live =
        ref.watch(realtimeStatusProvider).value ?? RealtimeStatus.connecting;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Wybór miejsc'),
        actions: <Widget>[
          RealtimeBadge(status: live),
          IconButton(
            tooltip: 'Odśwież plan sali',
            icon: const Icon(Icons.refresh),
            onPressed: () => ref.invalidate(seatSelectionProvider(screeningId)),
          ),
        ],
      ),
      body: AsyncView<SeatSelectionState>(
        value: value,
        onRetry: () => ref.invalidate(seatSelectionProvider(screeningId)),
        builder: (SeatSelectionState state) => _Loaded(
          state: state,
          live: live,
          controller: ref.read(seatSelectionProvider(screeningId).notifier),
          onReload: () => ref.invalidate(seatSelectionProvider(screeningId)),
          onCheckout: () => context.push(Routes.checkout(screeningId)),
        ),
      ),
    );
  }
}

class _Loaded extends StatelessWidget {
  const _Loaded({
    required this.state,
    required this.live,
    required this.controller,
    required this.onReload,
    required this.onCheckout,
  });

  final SeatSelectionState state;
  final RealtimeStatus live;
  final SeatSelection controller;
  final VoidCallback onReload;

  /// Przejście na ekran podsumowania i płatności.
  final VoidCallback onCheckout;

  @override
  Widget build(BuildContext context) {
    final SeatGrid grid = SeatGrid(state: state, onTap: controller.toggle);

    return Column(
      children: <Widget>[
        _Header(state: state),
        RealtimeWarning(status: live, onRefresh: onReload),
        if (state.cart.pendingBooking != null)
          _PendingPayment(onResume: onCheckout),
        if (state.notice case final SelectionNotice notice)
          _Notice(notice: notice, onClose: controller.dismissNotice),
        SeatLegend(prices: state.map.screening.prices),
        const Divider(height: 1),
        Expanded(
          child: InteractiveViewer(
            // `constrained: false` pozwala planowi być większym niż ekran —
            // bez tego siatka zostałaby wciśnięta w szerokość telefonu
            // i fotele byłyby nie do trafienia palcem.
            constrained: false,
            minScale: 0.5,
            maxScale: 3,
            boundaryMargin: const EdgeInsets.all(48),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: SizedBox(
                width: grid.planWidth,
                height: grid.planTotalHeight,
                child: grid,
              ),
            ),
          ),
        ),
        CartBar(
          state: state,
          onClear: controller.clear,
          onExpired: onReload,
          // Przy rozpoczętej płatności do niej prowadzi pasek nad planem, więc
          // paska koszyka nie obciążamy drugim takim samym przyciskiem.
          onCheckout: state.cart.pendingBooking == null ? onCheckout : null,
        ),
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.state});

  final SeatSelectionState state;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    final SeatMap plan = state.map;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            plan.screening.movie.brief.title,
            style: theme.textTheme.titleMedium,
          ),
          Text(
            '${plan.screening.startsAt.weekday} '
            '${plan.screening.startsAt.time} · '
            '${plan.screening.hall.name} · '
            '${plan.screening.hall.cinema.name}',
            style: theme.textTheme.bodySmall,
          ),
          Text(
            'Wolne: ${plan.summary.free} z ${plan.summary.total}',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// Komunikat po odrzuconym wyborze: konflikt, limit, miejsce bez ceny.
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

/// Rozpoczęta płatność zamraża plan sali (stan podaje serwer w koszyku).
class _PendingPayment extends StatelessWidget {
  const _PendingPayment({required this.onResume});

  /// Powrót do rozpoczętej płatności. Bez tego przycisku klient widziałby
  /// zamrożony plan sali i nie miałby stąd żadnego wyjścia — a rezerwacja
  /// czeka na pieniądze tylko kilka minut.
  final VoidCallback onResume;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      color: colors.tertiaryContainer,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            'Płatność za te miejsca jest już rozpoczęta. Do jej zakończenia '
            'planu sali nie można zmieniać.',
            style: TextStyle(color: colors.onTertiaryContainer),
          ),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: onResume,
              child: const Text('Wróć do płatności'),
            ),
          ),
        ],
      ),
    );
  }
}
