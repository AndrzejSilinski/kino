// Legenda planu sali: co znaczy kolor wypełnienia i co znaczy obwódka.
//
// Bez niej plan sali jest zagadką: kolory statusów są umowne, a kategorie
// cenowe mają barwy ustawione w panelu przez administratora — aplikacja ich
// nie zna z góry i nie może ich opisać w kodzie.

import 'package:cinema/features/common/hex_color.dart';
import 'package:cinema/models/screening.dart';
import 'package:flutter/material.dart';

class SeatLegend extends StatelessWidget {
  const SeatLegend({required this.prices, super.key});

  /// Cennik seansu — stąd biorą się nazwy kategorii, ich kolory i ceny.
  final List<PriceEntry> prices;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Wrap(
        spacing: 12,
        runSpacing: 4,
        children: <Widget>[
          _Item(color: colors.surfaceContainerHighest, label: 'wolne'),
          _Item(color: colors.primary, label: 'Twój wybór'),
          _Item(
            color: colors.onSurface.withValues(alpha: 0.18),
            label: 'zajęte',
          ),
          _Item(
            color: colors.onSurface.withValues(alpha: 0.35),
            label: 'sprzedane',
          ),
          for (final PriceEntry price in prices)
            _Item(
              // Kategoria to OBWÓDKA fotela, nie wypełnienie — dlatego
              // w legendzie też pokazujemy ją jako obwódkę.
              border: colorFromHex(price.color, colors.outline),
              label: '${price.categoryName} ${price.price.formatted}',
            ),
        ],
      ),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({required this.label, this.color, this.border});

  final String label;
  final Color? color;
  final Color? border;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Container(
          width: 14,
          height: 14,
          decoration: BoxDecoration(
            color: color ?? Colors.transparent,
            borderRadius: BorderRadius.circular(4),
            border: border == null
                ? null
                : Border.all(color: border!, width: 2),
          ),
        ),
        const SizedBox(width: 4),
        Text(label, style: Theme.of(context).textTheme.labelSmall),
      ],
    );
  }
}
