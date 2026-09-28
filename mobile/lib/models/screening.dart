// Repertuar i szczegóły seansu.
//
// Enumy przychodzą jako wartość plus etykieta po polsku (decyzja 25):
// rozgałęziamy się po wartości, a pokazujemy etykietę.

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/core/money.dart';
import 'package:cinema/models/cinema.dart';

/// Film w skrócie — tyle, ile pokazuje karta seansu w repertuarze.
class MovieBrief {
  const MovieBrief({
    required this.id,
    required this.slug,
    required this.title,
    required this.durationMinutes,
    required this.ageRating,
    this.posterUrl,
  });

  factory MovieBrief.fromJson(Map<String, Object?> json) {
    const String where = 'movie';
    return MovieBrief(
      id: jsonInt(json, 'id', where),
      slug: jsonString(json, 'slug', where),
      title: jsonString(json, 'title', where),
      durationMinutes: jsonInt(json, 'duration_minutes', where),
      ageRating: jsonString(json, 'age_rating', where),
      posterUrl: jsonStringOrNull(json, 'poster_url', where),
    );
  }

  final int id;
  final String slug;
  final String title;
  final int durationMinutes;

  /// Kategoria wiekowa jako napis z serwera (`15`, `b/o`).
  final String ageRating;

  /// Pełny adres z APP_URL albo null, gdy plakatu nie wgrano.
  final String? posterUrl;

  /// `3 godz. 0 min` — czas trwania w formie czytelnej na karcie.
  String get duration {
    final int hours = durationMinutes ~/ 60;
    final int minutes = durationMinutes % 60;
    if (hours == 0) {
      return '$minutes min';
    }
    return minutes == 0 ? '$hours godz.' : '$hours godz. $minutes min';
  }
}

/// Liczby miejsc z repertuaru — do informacji „zostało 12 miejsc”.
class SeatCounts {
  const SeatCounts({
    required this.total,
    required this.taken,
    required this.available,
  });

  factory SeatCounts.fromJson(Map<String, Object?> json) {
    const String where = 'screening.seats';
    return SeatCounts(
      total: jsonInt(json, 'total', where),
      taken: jsonInt(json, 'taken', where),
      available: jsonInt(json, 'available', where),
    );
  }

  final int total;
  final int taken;
  final int available;
}

/// Pozycja repertuaru — jedna karta na liście seansów dnia.
class ScreeningSummary {
  const ScreeningSummary({
    required this.id,
    required this.startsAt,
    required this.endsAt,
    required this.projectionType,
    required this.projectionTypeLabel,
    required this.languageVersionLabel,
    required this.hallName,
    required this.movie,
    required this.seats,
    required this.isSoldOut,
    required this.hasStarted,
    required this.isBookable,
  });

  factory ScreeningSummary.fromJson(Map<String, Object?> json) {
    const String where = 'screening';
    return ScreeningSummary(
      id: jsonInt(json, 'id', where),
      startsAt: CinemaTime.parse(jsonString(json, 'starts_at', where)),
      endsAt: CinemaTime.parse(jsonString(json, 'ends_at', where)),
      projectionType: jsonString(json, 'projection_type', where),
      projectionTypeLabel: jsonString(json, 'projection_type_label', where),
      languageVersionLabel: jsonString(json, 'language_version_label', where),
      hallName: jsonString(jsonChild(json, 'hall', where), 'name', 'hall'),
      movie: MovieBrief.fromJson(jsonChild(json, 'movie', where)),
      seats: SeatCounts.fromJson(jsonChild(json, 'seats', where)),
      isSoldOut: jsonBool(json, 'is_sold_out', where),
      hasStarted: jsonBool(json, 'has_started', where),
      isBookable: jsonBool(json, 'is_bookable', where),
    );
  }

  final int id;
  final CinemaTime startsAt;
  final CinemaTime endsAt;
  final String projectionType;
  final String projectionTypeLabel;
  final String languageVersionLabel;
  final String hallName;
  final MovieBrief movie;
  final SeatCounts seats;
  final bool isSoldOut;
  final bool hasStarted;

  /// O tym, czy seans można kupić, decyduje SERWER. Aplikacja nie liczy tego
  /// sama z godziny ani z liczby miejsc — inaczej przy zmianie reguł (bufor
  /// przed seansem, seans odwołany) telefon pokazywałby co innego niż web.
  final bool isBookable;

  /// Dlaczego karta jest nieklikalna — komunikat dla użytkownika.
  String? get unavailableReason {
    if (isSoldOut) {
      return 'Wyprzedane';
    }
    if (hasStarted) {
      return 'Seans się rozpoczął';
    }
    return isBookable ? null : 'Sprzedaż zakończona';
  }
}

/// Dzień z repertuarem — do kalendarza wyboru daty.
class ScreeningDate {
  const ScreeningDate({required this.date, required this.screeningsCount});

  factory ScreeningDate.fromJson(Map<String, Object?> json) {
    const String where = 'screening-dates[]';
    return ScreeningDate(
      date: jsonString(json, 'date', where),
      screeningsCount: jsonInt(json, 'screenings_count', where),
    );
  }

  /// `2026-09-18` — w takiej postaci wraca do zapytania o repertuar.
  final String date;
  final int screeningsCount;
}

class HallGrid {
  const HallGrid({required this.rows, required this.columns});

  factory HallGrid.fromJson(Map<String, Object?> json) {
    const String where = 'hall.grid';
    return HallGrid(
      rows: jsonInt(json, 'rows', where),
      columns: jsonInt(json, 'columns', where),
    );
  }

  final int rows;
  final int columns;
}

class Hall {
  const Hall({
    required this.id,
    required this.name,
    required this.grid,
    required this.cinema,
  });

  factory Hall.fromJson(Map<String, Object?> json) {
    const String where = 'hall';
    return Hall(
      id: jsonInt(json, 'id', where),
      name: jsonString(json, 'name', where),
      grid: HallGrid.fromJson(jsonChild(json, 'grid', where)),
      cinema: Cinema.fromJson(jsonChild(json, 'cinema', where)),
    );
  }

  final int id;
  final String name;

  /// Wymiary siatki sali — plan sali w bloku F rysuje się na nich.
  final HallGrid grid;
  final Cinema cinema;
}

/// Cennik seansu: kategoria miejsca i jej cena.
class PriceEntry {
  const PriceEntry({
    required this.categorySlug,
    required this.categoryName,
    required this.color,
    required this.price,
  });

  factory PriceEntry.fromJson(Map<String, Object?> json) {
    const String where = 'screening.prices[]';
    final Map<String, Object?> category = jsonChild(json, 'category', where);
    return PriceEntry(
      categorySlug: jsonString(category, 'slug', '$where.category'),
      categoryName: jsonString(category, 'name', '$where.category'),
      color: jsonString(category, 'color', '$where.category'),
      price: Money.fromJson(jsonChild(json, 'price', where), '$where.price'),
    );
  }

  final String categorySlug;
  final String categoryName;

  /// Kolor kategorii z panelu (`#4B5563`) — plan sali użyje go w bloku F.
  final String color;
  final Money price;
}

class MovieDetail {
  const MovieDetail({
    required this.brief,
    required this.description,
    required this.genres,
    this.originalTitle,
  });

  factory MovieDetail.fromJson(Map<String, Object?> json) {
    const String where = 'movie';
    return MovieDetail(
      brief: MovieBrief.fromJson(json),
      description: jsonString(json, 'description', where),
      originalTitle: jsonStringOrNull(json, 'original_title', where),
      genres: jsonList(
        json['genres'],
        '$where.genres',
      ).whereType<String>().toList(growable: false),
    );
  }

  final MovieBrief brief;
  final String description;
  final String? originalTitle;
  final List<String> genres;
}

/// Szczegóły seansu z `GET /api/v1/screenings/{id}`.
class ScreeningDetail {
  const ScreeningDetail({
    required this.id,
    required this.startsAt,
    required this.endsAt,
    required this.projectionTypeLabel,
    required this.languageVersionLabel,
    required this.status,
    required this.isBookable,
    required this.movie,
    required this.hall,
    required this.prices,
  });

  factory ScreeningDetail.fromJson(Map<String, Object?> json) {
    const String where = 'screening';
    return ScreeningDetail(
      id: jsonInt(json, 'id', where),
      startsAt: CinemaTime.parse(jsonString(json, 'starts_at', where)),
      endsAt: CinemaTime.parse(jsonString(json, 'ends_at', where)),
      projectionTypeLabel: jsonString(json, 'projection_type_label', where),
      languageVersionLabel: jsonString(json, 'language_version_label', where),
      status: jsonString(json, 'status', where),
      isBookable: jsonBool(json, 'is_bookable', where),
      movie: MovieDetail.fromJson(jsonChild(json, 'movie', where)),
      hall: Hall.fromJson(jsonChild(json, 'hall', where)),
      // Cennika może NIE BYĆ w odpowiedzi (pułapka DW). Serwer dokłada go
      // warunkowo — tylko wtedy, gdy trasa załadowała relację. Ekran seansu
      // i plan sali go dostają, ale seans w szczegółach rezerwacji już nie:
      // tam cennik jest zbędny, bo klient zapłacił, a cena stoi przy bilecie.
      // Pusta lista jest więc poprawną odpowiedzią, a `cheapest` oddaje wtedy
      // null, więc „od …” samo się nie pokazuje.
      prices: jsonList(json['prices'] ?? const <Object?>[], '$where.prices')
          .map((Object? item) => PriceEntry.fromJson(jsonMap(item, where)))
          .toList(growable: false),
    );
  }

  final int id;
  final CinemaTime startsAt;
  final CinemaTime endsAt;
  final String projectionTypeLabel;
  final String languageVersionLabel;
  final String status;
  final bool isBookable;
  final MovieDetail movie;
  final Hall hall;
  final List<PriceEntry> prices;

  /// Najniższa cena — pokazujemy ją jako „od …” przy wyborze miejsc.
  Money? get cheapest {
    if (prices.isEmpty) {
      return null;
    }
    return prices
        .reduce(
          (PriceEntry a, PriceEntry b) =>
              a.price.amount <= b.price.amount ? a : b,
        )
        .price;
  }
}
