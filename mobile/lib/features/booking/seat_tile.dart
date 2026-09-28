// Jeden fotel na planie sali.
//
// Kolor mówi o statusie, obwódka o kategorii cenowej — dzięki temu widać
// jednocześnie „czy wolne” i „ile kosztuje”, bez dwóch planów sali obok siebie.
//
// Klucz `ValueKey('seat-<id>')` jest tu celowo: testy widgetów sięgają po
// konkretny fotel po identyfikatorze, a nie po numerze w rzędzie, który
// powtarza się w każdym rzędzie.

import 'package:cinema/features/common/hex_color.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:flutter/material.dart';

class SeatTile extends StatelessWidget {
  const SeatTile({
    required this.seat,
    required this.status,
    required this.busy,
    required this.onTap,
    super.key,
  });

  /// Bok fotela i odstęp — stałe, bo od nich zależy rozmiar całego planu,
  /// który `InteractiveViewer` skaluje.
  static const double size = 34;
  static const double gap = 4;

  final Seat seat;

  /// Status DO POKAZANIA (z koszyka albo z planu sali), nie surowy `seat.status`.
  final SeatStatus status;

  final bool busy;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final Color category = colorFromHex(seat.category.color, colors.outline);
    final bool sellable = status.isFree && seat.price != null;

    final Color background = switch (status) {
      SeatStatus.heldByYou => colors.primary,
      SeatStatus.sold => colors.onSurface.withValues(alpha: 0.35),
      SeatStatus.held => colors.onSurface.withValues(alpha: 0.18),
      SeatStatus.unavailable => Colors.transparent,
      SeatStatus.free =>
        sellable
            ? colors.surfaceContainerHighest
            : colors.surfaceContainerHighest.withValues(alpha: 0.4),
    };
    final Color foreground = switch (status) {
      SeatStatus.heldByYou => colors.onPrimary,
      SeatStatus.sold || SeatStatus.held => colors.surface,
      _ => colors.onSurface,
    };

    return Semantics(
      button: onTap != null,
      enabled: onTap != null,
      label: _label(),
      child: Padding(
        padding: const EdgeInsets.all(gap / 2),
        child: SizedBox(
          width: size,
          height: size,
          child: Material(
            color: background,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(6),
              side: BorderSide(
                // Obwódka w kolorze kategorii tylko tam, gdzie cena ma
                // znaczenie: na sprzedanym i wyłączonym miejscu byłaby szumem.
                color: sellable || status.isMine
                    ? category
                    : Colors.transparent,
                width: 2,
              ),
            ),
            child: InkWell(
              onTap: onTap,
              child: Center(
                child: busy
                    ? SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: foreground,
                        ),
                      )
                    : Text(
                        status == SeatStatus.unavailable
                            ? '·'
                            : '${seat.number}',
                        style: TextStyle(fontSize: 12, color: foreground),
                      ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// Opis dla czytnika ekranu — na planie sali to jedyna droga do informacji,
  /// bo w kwadracie 34 na 34 piksele mieści się tylko numer.
  String _label() {
    final String state = switch (status) {
      SeatStatus.free => seat.price == null ? 'niedostępne' : 'wolne',
      SeatStatus.held => 'zajęte',
      SeatStatus.heldByYou => 'wybrane przez Ciebie',
      SeatStatus.sold => 'sprzedane',
      SeatStatus.unavailable => 'wyłączone ze sprzedaży',
    };
    final String price = seat.price == null ? '' : ', ${seat.price!.formatted}';
    return 'Miejsce ${seat.label}, ${seat.typeLabel}, $state$price';
  }
}
