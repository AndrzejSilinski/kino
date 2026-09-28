// Historia zakupów: lista rezerwacji od najnowszej, doczytywana przy
// przewijaniu.
//
// Doczytywanie jest w OSOBNYM widgecie z własnym stanem (`_LoadMore`), a nie
// w nasłuchu przewijania. Dwa powody. Pierwszy: kafel doczytywania powstaje
// dokładnie wtedy, gdy wchodzi w widok, więc `initState` jest naturalnym
// miejscem na jedno żądanie — bez progów w pikselach i bez zgadywania, kiedy
// „blisko końca”. Drugi: nieudane doczytanie zostaje W TYM kaflu (decyzja 325),
// więc lista nad nim zostaje na ekranie, a użytkownik dostaje przycisk
// ponowienia dokładnie tam, gdzie patrzy.

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/page.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/bookings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class BookingsScreen extends ConsumerWidget {
  const BookingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Moje bilety')),
      body: AsyncView<Paginated<Booking>>(
        value: ref.watch(bookingHistoryProvider),
        onRetry: () => ref.invalidate(bookingHistoryProvider),
        builder: (Paginated<Booking> page) => RefreshIndicator(
          onRefresh: () => ref.read(bookingHistoryProvider.notifier).reload(),
          child: page.isEmpty
              ? const _Empty()
              : ListView.builder(
                  padding: const EdgeInsets.all(16),
                  // Kafel doczytywania dochodzi na końcu, gdy jest co doczytać.
                  itemCount: page.items.length + (page.hasMore ? 1 : 0),
                  itemBuilder: (BuildContext context, int index) =>
                      index < page.items.length
                      ? _BookingTile(booking: page.items[index])
                      : const _LoadMore(),
                ),
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    // Lista przewijalna także wtedy, gdy jest pusta — inaczej nie da się jej
    // pociągnąć w dół, a to jedyny sposób odświeżenia, jaki zna użytkownik.
    return ListView(
      padding: const EdgeInsets.all(32),
      children: <Widget>[
        const Icon(Icons.confirmation_number_outlined, size: 48),
        const SizedBox(height: 16),
        Text(
          'Nie masz jeszcze żadnych rezerwacji. Kupione bilety pojawią się tutaj '
          'razem z kodem QR do pokazania przy wejściu.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ],
    );
  }
}

class _BookingTile extends StatelessWidget {
  const _BookingTile({required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    final ScreeningDetail? screening = booking.screening;
    final int count = booking.ticketsCount ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        // Cała lista niesie seans, więc nie dobieramy niczego osobno
        // (decyzja 319 — tu widać, po co był osobny provider koszyka).
        title: Text(screening?.movie.brief.title ?? 'Rezerwacja'),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (screening != null)
              Text(
                '${screening.startsAt.full} · ${screening.hall.cinema.name}',
              ),
            Text(
              count == 0
                  ? booking.statusLabel
                  : '${booking.statusLabel} · $count '
                        '${count == 1 ? "bilet" : "bilety"}',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
        trailing: Text(
          booking.total.formatted,
          style: theme.textTheme.titleSmall,
        ),
        isThreeLine: true,
        onTap: () => context.push(Routes.booking(booking.reference)),
      ),
    );
  }
}

/// Kafel na końcu listy: doczytuje następną stronę raz, przy pojawieniu się.
class _LoadMore extends ConsumerStatefulWidget {
  const _LoadMore();

  @override
  ConsumerState<_LoadMore> createState() => _LoadMoreState();
}

class _LoadMoreState extends ConsumerState<_LoadMore> {
  ApiError? _error;

  @override
  void initState() {
    super.initState();
    // Po klatce, nie w trakcie budowania drzewa: zmiana stanu providera
    // w `initState` unieważniłaby widget w czasie jego własnego budowania,
    // a Riverpod słusznie by na to nakrzyczał.
    WidgetsBinding.instance.addPostFrameCallback((_) => unawaited(_load()));
  }

  Future<void> _load() async {
    if (!mounted) {
      return;
    }
    final ApiError? error = await ref
        .read(bookingHistoryProvider.notifier)
        .loadMore();
    if (mounted) {
      setState(() => _error = error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ApiError? error = _error;
    if (error == null) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Column(
        children: <Widget>[
          Text(error.message, textAlign: TextAlign.center),
          const SizedBox(height: 8),
          FilledButton.tonal(
            onPressed: () {
              setState(() => _error = null);
              unawaited(_load());
            },
            child: const Text('Pokaż starsze'),
          ),
        ],
      ),
    );
  }
}
