// Konto: rejestracja, logowanie, wylogowanie, odczyt profilu.
//
// Repozytorium tłumaczy kontrakt API na modele i nic więcej nie robi:
// nie dotyka magazynu, nie decyduje o nawigacji, nie zna widgetów.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/models/user.dart';

/// Wynik logowania albo rejestracji: konto i token bearer.
class AuthResult {
  const AuthResult({required this.user, required this.token});

  factory AuthResult.fromJson(Map<String, Object?> data) => AuthResult(
    user: User.fromJson(jsonChild(data, 'user', 'auth')),
    token: jsonString(data, 'token', 'auth'),
  );

  final User user;
  final String token;
}

class AuthRepository {
  const AuthRepository(this._api);

  /// Trafia do nazwy tokenu w bazie (`personal_access_tokens.name`), więc
  /// na liście urządzeń widać, skąd jest sesja. Modelu telefonu nie czytamy:
  /// wymagałby kolejnej wtyczki, a wartości i tak nie używamy do niczego.
  static const String deviceName = 'Aplikacja Android';

  final ApiClient _api;

  Future<AuthResult> login({
    required String email,
    required String password,
  }) async {
    final Map<String, Object?> data = await _api.postJson(
      '/auth/login',
      body: <String, Object?>{
        'email': email,
        'password': password,
        'device_name': deviceName,
      },
    );
    return AuthResult.fromJson(data);
  }

  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final Map<String, Object?> data = await _api.postJson(
      '/auth/register',
      body: <String, Object?>{
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
        'device_name': deviceName,
      },
    );
    return AuthResult.fromJson(data);
  }

  /// Kasuje token TEGO urządzenia; inne sesje zostają (decyzja 17).
  Future<void> logout() async {
    await _api.postJson('/auth/logout');
  }

  Future<User> me() async => User.fromJson(await _api.getJson('/auth/me'));
}
