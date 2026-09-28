// Repertuar kina: pasek dni i lista seansów wybranego dnia.
//
// Seanse, których nie można kupić (wyprzedane, rozpoczęte, po czasie), są
// wyszarzone i nieklikalne — o tym, czy wolno kupić, decyduje pole
// `is_bookable` z serwera, nie własne liczenie po godzinie.

import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/features/common/poster.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class CinemaScreen extends ConsumerStatefulWidget {
  const CinemaScreen({required this.slug, super.key});

  final String slug;

  @override
  ConsumerState<CinemaScreen> createState() => _CinemaScreenState();
}

class _CinemaScreenState extends ConsumerState<CinemaScreen> {
  String? _date;

  @override
  Widget build(BuildContext context) {
    final AsyncValue<List<ScreeningDate>> dates = ref.watch(
      screeningDatesProvider(widget.slug),
    );

    return Scaffold(
      appBar: AppBar(
        title: const Text('Repertuar'),
        actions: <Widget>[
          IconButton(
            tooltip: 'Zmień kino',
            icon: const Icon(Icons.location_city),
            onPressed: () => context.go(Routes.cinemas),
          ),
        ],
      ),
      body: AsyncView<List<ScreeningDate>>(
        value: dates,
        onRetry: () => ref.invalidate(screeningDatesProvider(widget.slug)),
        builder: (List<ScreeningDate> days) {
          if (days.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text('To kino nie ma jeszcze repertuaru.'),
              ),
            );
          }
          final String selected = _date ?? days.first.date;
          return Column(
            children: <Widget>[
              _DayStrip(
                days: days,
                selected: selected,
                onSelected: (String date) => setState(() => _date = date),
              ),
              const Divider(height: 1),
              Expanded(
                child: _DaySchedule(slug: widget.slug, date: selected),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _DayStrip extends StatelessWidget {
  const _DayStrip({
    required this.days,
    required this.selected,
    required this.onSelected,
  });

  final List<ScreeningDate> days;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 64,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        itemCount: days.length,
        separatorBuilder: (BuildContext context, int index) =>
            const SizedBox(width: 8),
        itemBuilder: (BuildContext context, int index) {
          final ScreeningDate day = days[index];
          return ChoiceChip(
            selected: day.date == selected,
            onSelected: (_) => onSelected(day.date),
            label: Text('${day.date.substring(8)}.${day.date.substring(5, 7)}'),
            avatar: CircleAvatar(
              child: Text(
                '${day.screeningsCount}',
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
          );
        },
      ),
    );
  }
}

class _DaySchedule extends ConsumerWidget {
  const _DaySchedule({required this.slug, required this.date});

  final String slug;
  final String date;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final DaySchedule key = DaySchedule(cinemaSlug: slug, date: date);
    final AsyncValue<List<ScreeningSummary>> screenings = ref.watch(
      screeningsProvider(key),
    );

    return AsyncView<List<ScreeningSummary>>(
      value: screenings,
      onRetry: () => ref.invalidate(screeningsProvider(key)),
      builder: (List<ScreeningSummary> items) {
        if (items.isEmpty) {
          return const Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Text('Tego dnia nie ma seansów.'),
            ),
          );
        }
        return ListView.separated(
          itemCount: items.length,
          separatorBuilder: (BuildContext context, int index) =>
              const Divider(height: 1),
          itemBuilder: (BuildContext context, int index) =>
              _ScreeningTile(screening: items[index]),
        );
      },
    );
  }
}

class _ScreeningTile extends StatelessWidget {
  const _ScreeningTile({required this.screening});

  final ScreeningSummary screening;

  @override
  Widget build(BuildContext context) {
    final String? blocked = screening.unavailableReason;
    final ThemeData theme = Theme.of(context);
    final Color? muted = blocked == null ? null : theme.disabledColor;

    return Semantics(
      button: blocked == null,
      enabled: blocked == null,
      label:
          '${screening.movie.title}, godzina ${screening.startsAt.time}, '
          '${screening.hallName}, ${screening.projectionTypeLabel}'
          '${blocked == null ? '' : ', $blocked'}',
      child: ListTile(
        enabled: blocked == null,
        leading: Poster(url: screening.movie.posterUrl),
        title: Text(
          screening.movie.title,
          style: TextStyle(color: muted, fontWeight: FontWeight.w600),
        ),
        subtitle: Text(
          '${screening.startsAt.time} · ${screening.hallName} · '
          '${screening.projectionTypeLabel} · '
          '${screening.languageVersionLabel}\n'
          '${screening.movie.duration} · od lat ${screening.movie.ageRating}',
          style: TextStyle(color: muted),
        ),
        isThreeLine: true,
        trailing: blocked == null
            ? Text(
                'wolne\n${screening.seats.available}',
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall,
              )
            : Text(
                blocked,
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall?.copyWith(color: muted),
              ),
        onTap: blocked == null
            ? () => context.go(Routes.screening(screening.id))
            : null,
      ),
    );
  }
}
