// Stan połączenia na żywo: znaczek w pasku tytułu i pasek ostrzegawczy.
//
// Decyzja 308: klient MUSI widzieć, kiedy plan sali może być nieaktualny.
// Bez tego wybiera miejsca z obrazka, który zamarzł pięć minut temu w windzie,
// a odmowę dostaje dopiero przy kliknięciu — i wygląda to jak błąd aplikacji,
// nie jak utrata łącza. Znaczek mówi „na żywo”, pasek pojawia się tylko wtedy,
// gdy połączenia nie ma, i od razu daje przycisk odświeżenia.
//
// Oba widgety przyjmują status jako PARAMETR, nie czytają providera same.
// Dzięki temu testują się bez klienta WebSocketa, a ekran ma jedno miejsce,
// w którym pobiera stan.

import 'package:cinema/core/realtime.dart';
import 'package:flutter/material.dart';

class RealtimeBadge extends StatelessWidget {
  const RealtimeBadge({required this.status, super.key});

  final RealtimeStatus status;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final (IconData icon, String label, Color color) = switch (status) {
      RealtimeStatus.live => (Icons.bolt, 'Plan sali na żywo', colors.primary),
      RealtimeStatus.connecting => (
        Icons.sync,
        'Łączę z podglądem na żywo',
        colors.onSurfaceVariant,
      ),
      RealtimeStatus.offline => (
        Icons.cloud_off,
        'Brak połączenia na żywo',
        colors.error,
      ),
    };
    return Tooltip(
      message: label,
      child: Semantics(
        label: label,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Icon(icon, size: 20, color: color),
        ),
      ),
    );
  }
}

class RealtimeWarning extends StatelessWidget {
  const RealtimeWarning({
    required this.status,
    required this.onRefresh,
    super.key,
  });

  final RealtimeStatus status;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    if (status == RealtimeStatus.live) {
      return const SizedBox.shrink();
    }
    final ColorScheme colors = Theme.of(context).colorScheme;
    final bool connecting = status == RealtimeStatus.connecting;
    return Container(
      width: double.infinity,
      color: colors.secondaryContainer,
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              connecting
                  ? 'Łączę z podglądem na żywo…'
                  : 'Brak połączenia na żywo — plan sali może być nieaktualny.',
              style: TextStyle(color: colors.onSecondaryContainer),
            ),
          ),
          if (!connecting)
            TextButton(onPressed: onRefresh, child: const Text('Odśwież')),
        ],
      ),
    );
  }
}
