// Atrapa serwera dla powiadomień: konfiguracja klienta, urządzenia, zgoda
// na koncie i wylogowanie.
//
// Wydzielone z testu stanu (blok M1), bo od bloku M2 potrzebuje jej także test
// ekranu konta — a dwie kopie atrapy serwera rozjeżdżają się zawsze i zawsze
// w najgorszym momencie: jedna zna nowe pole odpowiedzi, druga nie, i test
// przechodzi tam, gdzie aplikacja by padła.

import 'dart:convert';

import 'package:cinema/core/secure_store.dart';
import 'package:http/http.dart' as http;

import 'fixtures.dart';

/// ULID urządzenia — taki sam kształt, jaki nadaje serwer.
const String deviceId = '01M2R0FF9TF8JNNQ9GBNZJ3TDQ';

http.Response reply(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

class FakePushApi {
  FakePushApi({
    this.config = 'client_config_push_android',
    this.deviceStatus = 204,
  });

  /// Fikstura konfiguracji klienta.
  final String config;

  /// Czym serwer odpowiada na wyrejestrowanie (404 = już go nie miał).
  final int deviceStatus;

  final List<Map<String, Object?>> registrations = <Map<String, Object?>>[];
  final List<String> deletions = <String>[];
  int consentCalls = 0;
  int logouts = 0;

  http.Response handle(http.Request request) {
    final String path = request.url.path;
    if (path.endsWith('/client-config')) {
      return reply(envelope(config));
    }
    if (path.endsWith('/auth/logout')) {
      logouts++;
      return http.Response('', 204);
    }
    if (path.endsWith('/account/notifications')) {
      consentCalls++;
      return reply(<String, Object?>{
        'data': <String, Object?>{
          'push_enabled': true,
          'push_consent_at': '2026-09-17T10:00:00+00:00',
          'screening_reminders': true,
        },
      });
    }
    if (request.method == 'DELETE') {
      deletions.add(path.split('/').last);
      if (deviceStatus == 204) {
        return http.Response('', 204);
      }
      // Kod, nie sam status: aplikacja rozgałęzia się po `code` (decyzja 197),
      // a 404 i 429 znaczą tu dwie zupełnie różne rzeczy — „już go nie ma"
      // (nie błąd) i „spróbuj później" (błąd do pokazania).
      final bool missing = deviceStatus == 404;
      return reply(<String, Object?>{
        'message': missing
            ? 'Nie znaleziono zasobu.'
            : 'Za dużo żądań. Spróbuj za chwilę.',
        'code': missing ? 'RESOURCE_NOT_FOUND' : 'TOO_MANY_REQUESTS',
      }, deviceStatus);
    }
    registrations.add(jsonDecode(request.body) as Map<String, Object?>);
    return reply(<String, Object?>{
      'data': <String, Object?>{
        'id': deviceId,
        'platform': 'android',
        'last_seen_at': '2026-09-17T10:00:00+00:00',
        'created_at': '2026-09-17T10:00:00+00:00',
      },
    }, registrations.length == 1 ? 201 : 200);
  }
}

/// Zapis urządzenia w magazynie — tak jak zapisuje go stan.
Future<void> remember(
  SecureStore store, {
  String id = deviceId,
  required String token,
}) => store.write(
  StoreKeys.pushDevice,
  jsonEncode(<String, String>{'id': id, 'token': token}),
);

Future<Map<String, Object?>?> stored(SecureStore store) async {
  final String? raw = await store.read(StoreKeys.pushDevice);
  return raw == null ? null : jsonDecode(raw) as Map<String, Object?>;
}
