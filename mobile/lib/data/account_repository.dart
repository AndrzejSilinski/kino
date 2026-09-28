// Konto: nazwa, hasło i avatar. Jedyne miejsce w aplikacji, które zna te adresy.
//
// Wszystko działa na ZALOGOWANYM użytkowniku — w adresach nie ma identyfikatora
// konta. To nie kosmetyka: gdyby był, trzeba by pilnować, że klient nie podstawi
// cudzego, a tak nie ma czego podstawiać.
//
// Potwierdzone w kodzie serwera (Etap 8, blok I):
//   - PATCH /account/profile — `{name}` od 2 do 120 znaków, serwer obcina spacje,
//   - PUT /account/password — `{current_password, password, password_confirmation}`,
//     minimum 8 znaków z literą i cyfrą, musi się różnić od obecnego; odpowiedź
//     niesie liczbę WYLOGOWANYCH urządzeń, bo zmiana hasła unieważnia pozostałe
//     tokeny (ten, na którym pracujemy, zostaje),
//   - POST /account/avatar — multipart, pole `avatar`, jpg/jpeg/png, do 5 MB,
//     nie mniej niż 128×128 i nie więcej niż 16 Mpx; serwer kadruje do kwadratu
//     256×256 i zapisuje jako JPEG pod losową nazwą,
//   - DELETE /account/avatar — idempotentne: 200 z `avatar_url: null` także wtedy,
//     gdy avatara nie było.
//
// Zmiany konta mają limit `account` per użytkownik, a hasło własny, ciaśniejszy
// — dlatego repozytorium NICZEGO nie ponawia (decyzja 289).

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/json.dart';
import 'package:cinema/models/user.dart';

class PasswordChanged {
  const PasswordChanged({required this.message, required this.revokedTokens});

  factory PasswordChanged.fromJson(Map<String, Object?> json) {
    const String where = 'password';
    return PasswordChanged(
      message: jsonString(json, 'message', where),
      revokedTokens: jsonInt(json, 'revoked_tokens', where),
    );
  }

  /// Komunikat z serwera — nie składamy własnego.
  final String message;

  /// Ile INNYCH urządzeń zostało wylogowanych. Warto to pokazać: klient, który
  /// zmienia hasło, bo podejrzewa włamanie, chce wiedzieć, że sesje napastnika
  /// właśnie przepadły.
  final int revokedTokens;
}

class AccountRepository {
  const AccountRepository(this._api);

  final ApiClient _api;

  /// Zmiana nazwy widocznej na koncie.
  Future<User> updateName(String name) async => User.fromJson(
    await _api.patchJson(
      '/account/profile',
      body: <String, Object?>{'name': name},
    ),
  );

  /// Zmiana hasła. `password_confirmation` wysyłamy zawsze — reguła
  /// `confirmed` po stronie serwera wymaga tego pola, a nie samej zgodności.
  Future<PasswordChanged> changePassword({
    required String current,
    required String next,
  }) async => PasswordChanged.fromJson(
    await _api.putJson(
      '/account/password',
      body: <String, Object?>{
        'current_password': current,
        'password': next,
        'password_confirmation': next,
      },
    ),
  );

  /// Wgranie avatara. Nazwa pliku niesie rozszerzenie, po którym serwer
  /// rozpoznaje typ — aplikacja wysyła zawsze JPEG.
  Future<User> uploadAvatar({
    required List<int> bytes,
    String filename = 'avatar.jpg',
  }) async => User.fromJson(
    await _api.postMultipart(
      '/account/avatar',
      field: 'avatar',
      filename: filename,
      bytes: bytes,
    ),
  );

  Future<User> removeAvatar() async =>
      User.fromJson(await _api.deleteJson('/account/avatar'));
}
