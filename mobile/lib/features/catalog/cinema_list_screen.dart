// Wybór kina — lista pogrupowana po miastach, tak jak zwraca ją serwer.

import 'package:cinema/features/common/async_view.dart';
import 'package:cinema/models/cinema.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class CinemaListScreen extends ConsumerWidget {
  const CinemaListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<List<CityCinemas>> cities = ref.watch(cinemasProvider);
    final String? selected = ref.watch(selectedCinemaProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Wybierz kino')),
      body: AsyncView<List<CityCinemas>>(
        value: cities,
        onRetry: () => ref.invalidate(cinemasProvider),
        builder: (List<CityCinemas> groups) => ListView(
          children: <Widget>[
            for (final CityCinemas group in groups) ...<Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                child: Text(
                  group.city,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              for (final Cinema cinema in group.cinemas)
                ListTile(
                  title: Text(cinema.name),
                  subtitle: Text(cinema.address),
                  trailing: cinema.slug == selected
                      ? const Icon(Icons.check)
                      : const Icon(Icons.chevron_right),
                  onTap: () {
                    ref
                        .read(selectedCinemaProvider.notifier)
                        .select(cinema.slug);
                    context.go(Routes.cinema(cinema.slug));
                  },
                ),
            ],
          ],
        ),
      ),
    );
  }
}
