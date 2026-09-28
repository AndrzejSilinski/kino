// Podpis subskrypcji kanału prywatnego.
//
// `POST /api/v1/broadcasting/auth` z `channel_name` i `socket_id` zwraca surowe
// `{"auth": "..."}` — bez koperty `data`, bo tego wymaga protokół Pushera.
//
// Nagłówków nie dokładamy tu ręcznie: `ApiClient` sam wysyła `X-Session-Id`
// (kanał seansu działa też dla kupującego BEZ konta) i token bearer, gdy
// użytkownik jest zalogowany (kanał rezerwacji wymaga konta). O tym, kto
// dostanie który kanał, rozstrzyga po stronie serwera Policy, nie middleware.

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/json.dart';

class BroadcastAuthRepository {
  const BroadcastAuthRepository(this._api);

  final ApiClient _api;

  Future<String> authorize(String channel, String socketId) async {
    const String where = 'broadcasting/auth';
    final Map<String, Object?> body = await _api.postWithoutEnvelope(
      '/broadcasting/auth',
      body: <String, Object?>{'channel_name': channel, 'socket_id': socketId},
    );
    return jsonString(body, 'auth', where);
  }
}
