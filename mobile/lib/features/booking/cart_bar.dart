// Pasek koszyka pod planem sali: co wybrałem, za ile i ile mam czasu.
//
// Odliczanie jest tu najważniejsze. Blokada miejsca wygasa po TTL z serwera,
// a klient, który tego nie widzi, wraca po kwadransie do koszyka, którego już
// nie ma. Licznik pokazuje czas do NAJWCZEŚNIEJSZEJ blokady — tak liczy go
// serwer, bo to ona wygaśnie pierwsza.
//
// Przycisk „Do płatności” pojawia się dopiero wtedy, gdy koszyk nie jest pusty
// I nie ma rozpoczętej płatności. W tym drugim przypadku prowadzi do niej
// przycisk z paska nad planem — dwa przyciski do tej samej rzeczy w dwóch
// miejscach ekranu to gotowy sposób na kliknięcie nie w to, co się chciało.

import 'package:cinema/features/common/countdown.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/state/booking.dart';
import 'package:flutter/material.dart';

class CartBar extends StatelessWidget {
  const CartBar({
    required this.state,
    required this.onClear,
    required this.onExpired,
    this.onCheckout,
    super.key,
  });

  final SeatSelectionState state;
  final VoidCallback onClear;

  /// Przejście do płatności. Null, gdy nie ma czym płacić albo gdy płatność
  /// jest już rozpoczęta — wtedy przycisku po prostu nie ma.
  final VoidCallback? onCheckout;

  /// Blokada wygasła — ekran musi pobrać plan sali i koszyk od nowa.
  final VoidCallback onExpired;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    final Cart cart = state.cart;
    final bool busy = state.busy.isNotEmpty;

    return Material(
      elevation: 8,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          // Informacje w jednym wierszu, przyciski w drugim. Wszystko w jednym
          // wierszu MIEŚCIŁO SIĘ, dopóki przycisków był zero albo jeden — po
          // dołożeniu „Do płatności” cena z licznikiem nie miała już miejsca
          // i wychodziła za ekran (pułapka DV). Na wąskim telefonie ten układ
          // pęka nawet bez trzeciego przycisku, więc poprawka jest tu, a nie
          // w teście.
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                cart.isEmpty
                    ? 'Wybierz miejsca na planie'
                    : 'Wybrane: ${cart.seats.map((CartSeat seat) => seat.label).join(', ')}',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 2),
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      cart.isEmpty
                          ? 'Najwyżej ${state.maxSeats} miejsc'
                          : '${cart.seatsCount} × miejsce · ${cart.total.formatted}',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  if (cart.expiresInSeconds case final int seconds) ...<Widget>[
                    const SizedBox(width: 8),
                    const Icon(Icons.timer_outlined, size: 16),
                    const SizedBox(width: 2),
                    Countdown(
                      seconds: seconds,
                      onExpired: onExpired,
                      style: theme.textTheme.titleMedium,
                    ),
                  ],
                ],
              ),
              if (cart.seatsCount > 0)
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: <Widget>[
                    TextButton(
                      onPressed: busy ? null : onClear,
                      child: const Text('Wyczyść wybór'),
                    ),
                    if (onCheckout != null) ...<Widget>[
                      const SizedBox(width: 8),
                      FilledButton(
                        onPressed: busy ? null : onCheckout,
                        child: const Text('Do płatności'),
                      ),
                    ],
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }
}
