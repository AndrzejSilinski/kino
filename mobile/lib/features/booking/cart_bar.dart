// Pasek koszyka pod planem sali: co wybrałem, za ile i ile mam czasu.
//
// Odliczanie jest tu najważniejsze. Blokada miejsca wygasa po TTL z serwera,
// a klient, który tego nie widzi, wraca po kwadransie do koszyka, którego już
// nie ma. Licznik pokazuje czas do NAJWCZEŚNIEJSZEJ blokady — tak liczy go
// serwer, bo to ona wygaśnie pierwsza.
//
// Przycisku „dalej do płatności” tu jeszcze nie ma: płatność to blok H razem
// z checkoutem i Stripe'em. Wolę pusty pasek niż przycisk, który nic nie robi.

import 'package:cinema/features/common/countdown.dart';
import 'package:cinema/models/cart.dart';
import 'package:cinema/state/booking.dart';
import 'package:flutter/material.dart';

class CartBar extends StatelessWidget {
  const CartBar({
    required this.state,
    required this.onClear,
    required this.onExpired,
    super.key,
  });

  final SeatSelectionState state;
  final VoidCallback onClear;

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
          child: Row(
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
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
                        Text(
                          cart.isEmpty
                              ? 'Najwyżej ${state.maxSeats} miejsc'
                              : '${cart.seatsCount} × miejsce · ${cart.total.formatted}',
                          style: theme.textTheme.titleMedium,
                        ),
                        if (cart.expiresInSeconds != null) ...<Widget>[
                          const SizedBox(width: 12),
                          const Icon(Icons.timer_outlined, size: 16),
                          const SizedBox(width: 2),
                          Countdown(
                            seconds: cart.expiresInSeconds!,
                            onExpired: onExpired,
                            style: theme.textTheme.titleMedium,
                          ),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
              if (cart.seatsCount > 0)
                TextButton(
                  onPressed: busy ? null : onClear,
                  child: const Text('Wyczyść wybór'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
