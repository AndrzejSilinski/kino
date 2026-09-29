// Repozytorium urządzeń push: adresy, ciała żądań i kształty odpowiedzi.
//
// Najważniejsze sprawdzenia w tym pliku dotyczą rzeczy, których na telefonie
// nie zobaczyłbym prawie nigdy, a które psują powiadomienia po cichu:
//   - `replaces` idzie TYLKO przy zmianie tokenu (inaczej serwer kasuje wiersz,
//     który właśnie zakłada),
//   - 404 przy wyrejestrowaniu to NIE błąd (serwer mógł już skasować urządzenie
//     przy wylogowaniu na innym sprzęcie),
//   - `platform` to zawsze `android` — 422 z listy dozwolonych wartości byłoby
//     błędem, którego nikt nie zobaczy, bo dzieje się w tle.

import 'dart:convert';

import 'package:cinema/core/api_client.dart';
import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/data/devices_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

final List<http.Request> sent = <http.Request>[];

DevicesRepository repositoryFor(
  http.Response Function(http.Request request) handler,
) => DevicesRepository(
  ApiClient(
    config: AppConfig(AppConfig.parseBaseUrl('http://localhost:8080')),
    httpClient: MockClient((http.Request request) async {
      sent.add(request);
      return handler(request);
    }),
  ),
);

http.Response json(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json'},
);

http.Response error(String code, int status) => json(<String, Object?>{
  'message': 'Nie znaleziono zasobu.',
  'code': code,
}, status);

Map<String, Object?> deviceEnvelope() => <String, Object?>{
  'data': <String, Object?>{
    'id': '01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
    'platform': 'android',
    'last_seen_at': '2026-09-17T10:00:00+00:00',
    'created_at': '2026-09-17T10:00:00+00:00',
  },
};

Map<String, Object?> body(http.Request request) =>
    jsonDecode(request.body) as Map<String, Object?>;

void main() {
  setUp(sent.clear);

  test('rejestracja idzie PUT-em z platformą android', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => json(deviceEnvelope(), 201),
    );

    final RegisteredDevice device = await repository.register(
      token: 'tokenFcmPierwszy'.padRight(40, 'x'),
    );

    expect(sent.single.method, 'PUT');
    expect(sent.single.url.path, endsWith('/account/devices'));
    expect(body(sent.single)['platform'], 'android');
    // Pierwsza rejestracja nie ma czego zastępować.
    expect(body(sent.single).containsKey('replaces'), isFalse);
    expect(device.id, '01M2R0FF9TF8JNNQ9GBNZJ3TDQ');
  });

  test('nowy token zastępuje stary jednym żądaniem', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => json(deviceEnvelope()),
    );

    await repository.register(token: 'nowyToken', replaces: 'staryToken');

    expect(body(sent.single)['replaces'], 'staryToken');
  });

  test('ten sam token NIE wysyła replaces', () async {
    // Serwer usuwa wiersz wskazany przez `replaces` w tej samej transakcji,
    // w której zakłada nowy — a tu byłby to ten sam wiersz.
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => json(deviceEnvelope()),
    );

    await repository.register(token: 'tenSamToken', replaces: 'tenSamToken');

    expect(body(sent.single).containsKey('replaces'), isFalse);
  });

  test('wyrejestrowanie po ULID-zie; 204 bez treści to sukces', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => http.Response('', 204),
    );

    final bool removed = await repository.unregister(
      '01M2R0FF9TF8JNNQ9GBNZJ3TDQ',
    );

    expect(removed, isTrue);
    expect(sent.single.method, 'DELETE');
    expect(
      sent.single.url.path,
      endsWith('/account/devices/01M2R0FF9TF8JNNQ9GBNZJ3TDQ'),
    );
  });

  test('urządzenia, którego serwer nie ma, NIE traktujemy jak błędu', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => error(ApiError.resourceNotFound, 404),
    );

    expect(await repository.unregister('01M2R0FF9TF8JNNQ9GBNZJ3TDQ'), isFalse);
  });

  test('zły kształt identyfikatora też znaczy „nie ma go tam"', () async {
    // Adres ma wzorzec (26 znaków ULID-a), więc śmieciowy zapis nie dociera
    // nawet do kontrolera — wraca ENDPOINT_NOT_FOUND.
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => error(ApiError.endpointNotFound, 404),
    );

    expect(await repository.unregister('smieci'), isFalse);
  });

  test('inny błąd serwera leci dalej', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => error(ApiError.tooManyRequests, 429),
    );

    await expectLater(
      repository.unregister('01M2R0FF9TF8JNNQ9GBNZJ3TDQ'),
      throwsA(isA<ApiError>()),
    );
  });

  test('zgoda na koncie idzie PATCH-em i tylko jednym polem', () async {
    // `screening_reminders` zostawiamy w spokoju: użytkownik go w tym momencie
    // nie dotykał, a PATCH nadpisałby wartość ustawioną w przeglądarce.
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'data': <String, Object?>{
          'push_enabled': true,
          'push_consent_at': '2026-09-17T10:00:00+00:00',
          'screening_reminders': true,
        },
      }),
    );

    final NotificationSettings settings = await repository.allowPush(true);

    expect(sent.single.method, 'PATCH');
    expect(sent.single.url.path, endsWith('/account/notifications'));
    expect(body(sent.single), <String, Object?>{'push_enabled': true});
    expect(settings.pushEnabled, isTrue);
    expect(settings.screeningReminders, isTrue);
  });

  test('odczyt ustawień powiadomień', () async {
    final DevicesRepository repository = repositoryFor(
      (http.Request request) => json(<String, Object?>{
        'data': <String, Object?>{
          'push_enabled': false,
          'push_consent_at': null,
          'screening_reminders': true,
        },
      }),
    );

    final NotificationSettings settings = await repository.settings();

    expect(sent.single.method, 'GET');
    expect(settings.pushEnabled, isFalse);
  });
}
