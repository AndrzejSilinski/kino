// Providery katalogu: kina, wybrane kino, dni i repertuar.

import 'dart:async';

import 'package:cinema/data/catalog_repository.dart';
import 'package:cinema/models/cinema.dart';
import 'package:cinema/models/screening.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<CatalogRepository> catalogRepositoryProvider =
    Provider<CatalogRepository>(
      (Ref ref) => CatalogRepository(ref.watch(apiClientProvider)),
    );

final FutureProvider<List<CityCinemas>> cinemasProvider =
    FutureProvider<List<CityCinemas>>(
      (Ref ref) => ref.watch(catalogRepositoryProvider).cinemas(),
    );

/// Dni z repertuarem dla kina (slug). Typ providera zostawiamy wnioskowaniu:
/// nazwy klas rodzin różnią się między wersjami Riverpoda, a `final` bez
/// adnotacji jest odporne na te zmiany.
final screeningDatesProvider =
    FutureProvider.family<List<ScreeningDate>, String>(
      (Ref ref, String slug) =>
          ref.watch(catalogRepositoryProvider).screeningDates(slug),
    );

/// Repertuar jednego dnia w jednym kinie.
final screeningsProvider =
    FutureProvider.family<List<ScreeningSummary>, DaySchedule>(
      (Ref ref, DaySchedule day) => ref
          .watch(catalogRepositoryProvider)
          .screenings(cinemaSlug: day.cinemaSlug, date: day.date),
    );

final screeningProvider = FutureProvider.family<ScreeningDetail, int>(
  (Ref ref, int id) => ref.watch(catalogRepositoryProvider).screening(id),
);

/// Klucz rodziny providerów: kino plus dzień. Rodzina porównuje klucze przez
/// `==`, więc typ MUSI mieć równość po wartości — inaczej każde wejście na
/// ekran tworzyłoby nowy provider i nowe żądanie (pułapka CU).
class DaySchedule {
  const DaySchedule({required this.cinemaSlug, required this.date});

  final String cinemaSlug;
  final String date;

  @override
  bool operator ==(Object other) =>
      other is DaySchedule &&
      other.cinemaSlug == cinemaSlug &&
      other.date == date;

  @override
  int get hashCode => Object.hash(cinemaSlug, date);
}

/// Ostatnio wybrane kino — zapamiętane między uruchomieniami aplikacji.
///
/// Trzymamy je w tym samym magazynie co token (decyzja 278). To nie jest
/// sekret, ale dokładanie drugiego mechanizmu zapisu (SharedPreferences)
/// tylko dla jednego sluga oznaczałoby kolejną wtyczkę natywną i kolejne
/// miejsce, w którym dane zostają po wylogowaniu.
class SelectedCinema extends Notifier<String?> {
  static const String storeKey = 'selected_cinema_slug';

  @override
  String? build() {
    unawaited(_restore());
    return null;
  }

  Future<void> _restore() async {
    final String? slug = await ref.read(secureStoreProvider).read(storeKey);
    if (slug != null && state == null) {
      state = slug;
    }
  }

  Future<void> select(String slug) async {
    state = slug;
    await ref.read(secureStoreProvider).write(storeKey, slug);
  }
}

final NotifierProvider<SelectedCinema, String?> selectedCinemaProvider =
    NotifierProvider<SelectedCinema, String?>(SelectedCinema.new);
