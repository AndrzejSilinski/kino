// Szczegóły seansu: film, sala, godziny i cennik, a z niego wejście na plan
// sali. Przycisk jest nieaktywny dokładnie wtedy, gdy serwer mówi, że seansu
// nie można kupić (`is_bookable`) — aplikacja nie liczy tego z godziny.

import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/features/common/hex_color.dart';
import 'package:cinema/features/common/poster.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

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
              backgroundColor: colorFromHex(
                entry.color,
                theme.colorScheme.primary,
              ),
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
          onPressed: detail.isBookable
              ? () => context.go(Routes.seats(detail.id))
              : null,
          child: Text(
            detail.isBookable ? 'Wybierz miejsca' : 'Sprzedaż zakończona',
          ),
        ),
      ],
    );
  }
}
