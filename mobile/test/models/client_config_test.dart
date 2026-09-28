// Model powstał z PRAWDZIWEJ odpowiedzi serwera zebranej w rozpoznaniu
// (test/fixtures/client_config.json), a nie z domysłów o kształcie API.

import 'dart:convert';
import 'dart:io';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/models/client_config.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, Object?> fixtureData() {
  final String raw = File('test/fixtures/client_config.json')
      .readAsStringSync();
  final Map<String, Object?> envelope = jsonDecode(raw) as Map<String, Object?>;
  return envelope['data']! as Map<String, Object?>;
}

void main() {
  test('parsuje konfigurację z odpowiedzi serwera', () {
    final ClientConfig config = ClientConfig.fromJson(fixtureData());

    expect(config.apiVersion, 'v1');
    expect(config.realtime.broadcaster, 'reverb');
    expect(config.realtime.path, '/app');
    expect(config.realtime.key, isNotEmpty);
    expect(config.booking.seatLockTtl, const Duration(minutes: 10));
    expect(config.booking.maxSeatsPerSession, 10);
    expect(config.booking.paymentWindow, const Duration(minutes: 10));
    expect(config.pushEnabled, isTrue);
  });

  test('brak pola daje INVALID_RESPONSE ze wskazaniem miejsca', () {
    final Map<String, Object?> data = fixtureData();
    final Map<String, Object?> booking =
        data['booking']! as Map<String, Object?>;
    booking.remove('max_seats_per_session');

    try {
      ClientConfig.fromJson(data);
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.invalidResponse);
      expect(
        error.context['where'],
        'client-config.booking.max_seats_per_session',
      );
    }
  });
}
