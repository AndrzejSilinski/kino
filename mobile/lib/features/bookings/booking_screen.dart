// Ekran jednej rezerwacji: seans, status, bilety z kodami QR i PDF.
//
// To ekran, na którym klient stoi dziesięć minut przed seansem, więc rządzą nim
// dwie zasady. Pierwsza: nic tu nie jest zgadywane — status i wszystkie etykiety
// pochodzą z serwera (decyzja 309). Druga: awaria dodatku nie gasi treści —
// gdy nie uda się pobrać obrazu kodu, bilet nadal widać razem z informacją,
// że jest ważny niezależnie od tego, czy kod się wyświetlił.
//
// PDF udostępniamy systemowym oknem, a nie zapisujemy do katalogu publicznego
// (decyzja 327). Pobiera go aplikacja, z tokenem — adres bez tokenu oddaje 401.

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/features/bookings/ticket_card.dart';
import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/models/booking.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/models/ticket.dart';
import 'package:cinema/state/bookings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class BookingScreen extends ConsumerWidget {
  const BookingScreen({required this.reference, super.key});

  final String reference;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Rezerwacja'),
        actions: <Widget>[
          IconButton(
            tooltip: 'Odśwież rezerwację',
            icon: const Icon(Icons.refresh),
            onPressed: () => ref.invalidate(bookingDetailsProvider(reference)),
          ),
        ],
      ),
      body: AsyncView<Booking>(
        value: ref.watch(bookingDetailsProvider(reference)),
        onRetry: () => ref.invalidate(bookingDetailsProvider(reference)),
        builder: (Booking booking) => _Loaded(booking: booking),
      ),
    );
  }
}

class _Loaded extends ConsumerWidget {
  const _Loaded({required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ThemeData theme = Theme.of(context);
    final Map<String, String> headers = ref
        .read(ticketImageHeadersProvider)
        .headers();

    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        if (booking.screening case final ScreeningDetail seans)
          _Header(screening: seans),
        const SizedBox(height: 12),
        Text(booking.statusLabel, style: theme.textTheme.titleMedium),
        Text('Numer ${booking.reference}', style: theme.textTheme.bodySmall),
        Text(
          // „Zapłacono” tylko wtedy, gdy naprawdę zapłacono: przy rezerwacji
          // anulowanej albo wygasłej ten napis byłby nieprawdą.
          booking.status.isPaid
              ? 'Zapłacono ${booking.total.formatted}'
              : 'Kwota ${booking.total.formatted}',
          style: theme.textTheme.bodySmall,
        ),
        if (booking.cancellation case final BookingCancellation anulowanie)
          _Cancellation(cancellation: anulowanie),
        const Divider(height: 32),
        if (booking.tickets.isEmpty)
          Text(
            'Ta rezerwacja nie ma biletów.',
            style: theme.textTheme.bodyMedium,
          )
        else ...<Widget>[
          Text(
            booking.tickets.length == 1
                ? 'Bilet'
                : 'Bilety (${booking.tickets.length})',
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 12),
          for (final Ticket ticket in booking.tickets)
            TicketCard(ticket: ticket, headers: headers),
          const SizedBox(height: 8),
          _PdfButton(reference: booking.reference),
        ],
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.screening});

  final ScreeningDetail screening;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(screening.movie.brief.title, style: theme.textTheme.headlineSmall),
        const SizedBox(height: 4),
        Text(screening.startsAt.full, style: theme.textTheme.titleMedium),
        Text(
          '${screening.hall.name} · ${screening.hall.cinema.name}',
          style: theme.textTheme.bodyMedium,
        ),
        Text(
          '${screening.hall.cinema.address}, ${screening.hall.cinema.city}',
          style: theme.textTheme.bodySmall,
        ),
        Text(
          '${screening.projectionTypeLabel} · ${screening.languageVersionLabel}',
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }
}

class _Cancellation extends StatelessWidget {
  const _Cancellation({required this.cancellation});

  final BookingCancellation cancellation;

  @override
  Widget build(BuildContext context) {
    final String refund = switch (cancellation.refund) {
      'refunded' => 'Pieniądze zostały zwrócone.',
      'pending' => 'Zwrot pieniędzy jest w toku.',
      _ => 'Nie było czego zwracać.',
    };
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Text(
        'Anulowana ${cancellation.cancelledAt.full}. $refund',
        style: Theme.of(context).textTheme.bodySmall,
      ),
    );
  }
}

/// Pobranie PDF-a i podanie go systemowi.
class _PdfButton extends ConsumerWidget {
  const _PdfButton({required this.reference});

  final String reference;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bool busy = ref.watch(ticketsPdfProvider);
    return OutlinedButton.icon(
      onPressed: busy ? null : () => unawaited(_share(context, ref)),
      icon: const Icon(Icons.picture_as_pdf_outlined),
      label: Text(busy ? 'Pobieram PDF…' : 'Bilety w PDF'),
    );
  }

  Future<void> _share(BuildContext context, WidgetRef ref) async {
    final ScaffoldMessengerState messenger = ScaffoldMessenger.of(context);
    final ApiError? error = await ref
        .read(ticketsPdfProvider.notifier)
        .share(reference);
    if (error == null) {
      return;
    }
    // `context` po `await` może być już nieaktualny, dlatego messengera
    // wzięliśmy PRZED oczekiwaniem — to jedyny bezpieczny sposób pokazania
    // komunikatu po operacji sieciowej.
    messenger.showSnackBar(
      SnackBar(content: Text('Nie udało się pobrać PDF-a. ${error.message}')),
    );
  }
}
