// Kina pogrupowane po miastach — kształt z `GET /api/v1/cinemas`.

import 'package:cinema/core/json.dart';

class Cinema {
  const Cinema({
    required this.id,
    required this.slug,
    required this.name,
    required this.city,
    required this.address,
    required this.timezone,
  });

  factory Cinema.fromJson(Map<String, Object?> json) {
    const String where = 'cinema';
    return Cinema(
      id: jsonInt(json, 'id', where),
      slug: jsonString(json, 'slug', where),
      name: jsonString(json, 'name', where),
      city: jsonString(json, 'city', where),
      address: jsonString(json, 'address', where),
      timezone: jsonString(json, 'timezone', where),
    );
  }

  final int id;

  /// Kino identyfikujemy slugiem, nie liczbą (decyzja 205) — ten sam adres
  /// działa w SPA i w aplikacji.
  final String slug;
  final String name;
  final String city;
  final String address;

  /// Strefa kina. Godzin i tak nie przeliczamy (decyzja 24), ale pokazujemy
  /// ją w szczegółach seansu, gdy różni się od strefy telefonu.
  final String timezone;
}

/// Kina jednego miasta — serwer grupuje je za nas, więc lista jest gotowa
/// do wyświetlenia bez sortowania po stronie aplikacji.
class CityCinemas {
  const CityCinemas({required this.city, required this.cinemas});

  factory CityCinemas.fromJson(Map<String, Object?> json) {
    const String where = 'cinemas[]';
    return CityCinemas(
      city: jsonString(json, 'city', where),
      cinemas: jsonList(json['cinemas'], '$where.cinemas')
          .map((Object? item) => Cinema.fromJson(jsonMap(item, where)))
          .toList(growable: false),
    );
  }

  final String city;
  final List<Cinema> cinemas;
}
