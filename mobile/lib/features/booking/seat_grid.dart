// Siatka foteli — plan sali rysowany po WSPÓŁRZĘDNYCH, nie po kolejności listy.
//
// Sala ma przejścia, więc w rzędzie bywa dziura: miejsce o numerze 8 może stać
// w kolumnie 9. Gdybyśmy rysowali fotele jeden po drugim, plan w aplikacji nie
// zgadzałby się z układem sali. Wymiary siatki podaje serwer
// (`screening.hall.grid`), a każde miejsce swoje `position.x` i `position.y`.
//
// Rozmiar całości jest policzalny z góry (stały bok fotela), dlatego
// `InteractiveViewer` może go swobodnie skalować i przesuwać.

import 'package:cinema/features/booking/seat_tile.dart';
import 'package:cinema/models/seat_map.dart';
import 'package:cinema/state/booking.dart';
import 'package:flutter/material.dart';

class SeatGrid extends StatelessWidget {
  const SeatGrid({required this.state, required this.onTap, super.key});

  final SeatSelectionState state;
  final void Function(Seat seat) onTap;

  /// Szerokość kolumny z literą rzędu.
  static const double rowLabelWidth = 22;

  static const double cell = SeatTile.size + SeatTile.gap;

  double get planWidth => rowLabelWidth + state.map.columns * cell;

  double get planHeight => state.map.rows * cell;

  /// Wysokość z paskiem „EKRAN” i odstępem pod nim — tyle miejsca musi dostać
  /// plan od ekranu, który go skaluje.
  double get planTotalHeight => planHeight + 34;

  @override
  Widget build(BuildContext context) {
    final SeatMap plan = state.map;
    // Indeks po współrzędnych: (y, x) -> miejsce.
    final Map<int, Map<int, Seat>> grid = <int, Map<int, Seat>>{};
    for (final Seat seat in plan.ordered) {
      grid.putIfAbsent(seat.y, () => <int, Seat>{})[seat.x] = seat;
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        _Screen(width: planWidth),
        const SizedBox(height: 12),
        for (int y = 0; y < plan.rows; y++)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              SizedBox(
                width: rowLabelWidth,
                child: Text(
                  _rowLabel(grid[y]),
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ),
              for (int x = 0; x < plan.columns; x++)
                if (grid[y]?[x] case final Seat seat)
                  SeatTile(
                    key: ValueKey<String>('seat-${seat.id}'),
                    seat: seat,
                    status: state.statusOf(seat),
                    busy: state.isBusy(seat),
                    onTap: state.canTap(seat) ? () => onTap(seat) : null,
                  )
                else
                  const SizedBox(width: cell, height: cell),
            ],
          ),
      ],
    );
  }

  /// Litera rzędu z pierwszego miejsca w tym rzędzie. Rząd, w którym są same
  /// przejścia, nie ma etykiety.
  String _rowLabel(Map<int, Seat>? row) {
    if (row == null || row.isEmpty) {
      return '';
    }
    return row.values.first.row;
  }
}

/// Pasek „EKRAN” nad pierwszym rzędem — bez niego nie widać, z której strony
/// sali są rzędy przednie.
class _Screen extends StatelessWidget {
  const _Screen({required this.width});

  final double width;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: width,
      height: 22,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: colors.surfaceContainerHighest,
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(12)),
      ),
      child: Text('EKRAN', style: Theme.of(context).textTheme.labelSmall),
    );
  }
}
