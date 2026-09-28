// Szczegóły seansu: film, sala, godziny i cennik. Wybór miejsc dochodzi
// w bloku F — przycisk jest już na ekranie, ale nieaktywny.

import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/features/common/poster.dart';
import 'package:cinema/models/screening.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:cinema/state/catalog.dart';

class ScreeningScreen extends ConsumerWidget {
  const ScreeningScreen({required this.id, super.key});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<ScreeningDetail> screening = ref.watch(
      screeningProvider(id),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Seans')),
      body: AsyncView<ScreeningDetail>(
        value: screening,
        onRetry: () => ref.invalidate(screeningProvider(id)),
        builder: (ScreeningDetail detail) => _Details(detail: detail),
      ),
    );
  }
}

class _Details extends StatelessWidget {
  const _Details({required this.detail});

  final ScreeningDetail detail;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Poster(url: detail.movie.brief.posterUrl, width: 96),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    detail.movie.brief.title,
                    style: theme.textTheme.titleLarge,
                  ),
                  if (detail.movie.originalTitle != null)
                    Text(
                      detail.movie.originalTitle!,
                      style: theme.textTheme.bodySmall,
                    ),
                  const SizedBox(height: 8),
                  Text(detail.movie.genres.join(', ')),
                  Text(
                    '${detail.movie.brief.duration} · '
                    'od lat ${detail.movie.brief.ageRating}',
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(detail.startsAt.full, style: theme.textTheme.titleMedium),
                const SizedBox(height: 4),
                Text('Koniec: ${detail.endsAt.time}'),
                Text(
                  '${detail.hall.cinema.name}, ${detail.hall.name} · '
                  '${detail.projectionTypeLabel} · '
                  '${detail.languageVersionLabel}',
                ),
                Text(detail.hall.cinema.address),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Text('Cennik', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        for (final PriceEntry entry in detail.prices)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              radius: 10,
              backgroundColor: _color(entry.color, theme),
            ),
            title: Text(entry.categoryName),
            trailing: Text(entry.price.formatted),
          ),
        const SizedBox(height: 16),
        Text('Opis', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(detail.movie.description),
        const SizedBox(height: 24),
        FilledButton(
          // Wybór miejsc dochodzi w bloku F; przycisk jest tu po to, żeby
          // ekran był kompletny i żeby test pilnował jego stanu.
          onPressed: null,
          child: Text(
            detail.isBookable
                ? 'Wybierz miejsca (wkrótce)'
                : 'Sprzedaż zakończona',
          ),
        ),
      ],
    );
  }

  /// Kolor kategorii przychodzi jako `#RRGGBB` z panelu. Zły format nie może
  /// wywrócić ekranu — wtedy bierzemy kolor z motywu.
  Color _color(String value, ThemeData theme) {
    final RegExpMatch? match = RegExp(r'^#([0-9a-fA-F]{6})$')
        .firstMatch(value.trim());
    if (match == null) {
      return theme.colorScheme.primary;
    }
    return Color(0xFF000000 | int.parse(match.group(1)!, radix: 16));
  }
}
