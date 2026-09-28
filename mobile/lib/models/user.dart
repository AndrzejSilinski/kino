// Zalogowany użytkownik — pola z UserResource (backend, Etap 3 i 8).

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/core/json.dart';

class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.roleLabel,
    this.avatarUrl,
    this.createdAt,
  });

  factory User.fromJson(Map<String, Object?> json) {
    const String where = 'user';
    final String? created = jsonStringOrNull(json, 'created_at', where);
    return User(
      id: jsonInt(json, 'id', where),
      name: jsonString(json, 'name', where),
      email: jsonString(json, 'email', where),
      role: jsonString(json, 'role', where),
      roleLabel: jsonString(json, 'role_label', where),
      avatarUrl: jsonStringOrNull(json, 'avatar_url', where),
      createdAt: created == null ? null : CinemaTime.parse(created),
    );
  }

  final int id;
  final String name;
  final String email;

  /// Surowa wartość enuma plus etykieta po polsku (decyzja 25) — aplikacja
  /// rozgałęzia się po `role`, a pokazuje `roleLabel`.
  final String role;
  final String roleLabel;

  /// Pełny adres z APP_URL albo null, gdy konto nie ma avatara.
  final String? avatarUrl;
  final CinemaTime? createdAt;

  /// Inicjał do kółka z avatarem, gdy zdjęcia nie ma.
  String get initial => name.isEmpty ? '?' : name.substring(0, 1).toUpperCase();
}
