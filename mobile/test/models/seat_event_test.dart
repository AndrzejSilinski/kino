// Parsowanie zdarzeń kanału seansu. Kształt z rozpoznania fazy 2.
//
// Reguła jest tu inna niż w modelach REST: zdarzenie, którego nie rozumiemy,
// POMIJAMY (null), zamiast rzucać wyjątkiem. Ekran wyboru miejsc nie może
// zgasnąć z powodu jednej dziwnej ramki ze strumienia.

import 'package:cinema/core/realtime.dart';
import 'package:cinema/models/seat_event.dart';
import 'package:flutter_test/flutter_test.dart';

RealtimeEvent event(String name, Map<String, Object?> data) =>
    RealtimeEvent(channel: 'private-screenings.380', event: name, data: data);

void main() {
  test('czyta seats.changed ze stanem absolutnym miejsc', () {
    final SeatsEvent parsed = SeatsEvent.tryParse(
      event('seats.changed', <String, Object?>{
        'screening_id': 380,
        'version': 5,
        'seats': <String, Object?>{
          'held': <int>[890, 891],
          'free': <int>[892],
        },
      }),
    )!;

    expect(parsed.screeningId, 380);
    expect(parsed.version, 5);
    expect(parsed.isResync, isFalse);
    expect(parsed.changes['held'], <int>[890, 891]);
    expect(parsed.changes['free'], <int>[892]);
  });

  test('seats.resync nie niesie zmian, tylko prośbę o pełne pobranie', () {
    final SeatsEvent parsed = SeatsEvent.tryParse(
      event('seats.resync', <String, Object?>{
        'screening_id': 380,
        'version': 12,
      }),
    )!;

    expect(parsed.isResync, isTrue);
    expect(parsed.version, 12);
    expect(parsed.changes, isEmpty);
  });

  test('brak wersji albo identyfikatora seansu to zdarzenie do pominięcia', () {
    expect(
      SeatsEvent.tryParse(
        event('seats.changed', <String, Object?>{'screening_id': 380}),
      ),
      isNull,
    );
    expect(
      SeatsEvent.tryParse(
        event('seats.changed', <String, Object?>{'version': 5}),
      ),
      isNull,
    );
    expect(
      SeatsEvent.tryParse(
        event('seats.changed', <String, Object?>{
          'screening_id': '380',
          'version': 5,
        }),
      ),
      isNull,
    );
  });

  test('zdarzenie nie o planie sali jest pomijane', () {
    expect(
      SeatsEvent.tryParse(
        event('booking.status-changed', <String, Object?>{
          'screening_id': 380,
          'version': 5,
        }),
      ),
      isNull,
    );
  });

  test('dziwny kształt pola seats daje puste zmiany, nie wyjątek', () {
    final SeatsEvent parsed = SeatsEvent.tryParse(
      event('seats.changed', <String, Object?>{
        'screening_id': 380,
        'version': 5,
        'seats': 'to nie jest mapa',
      }),
    )!;

    expect(parsed.changes, isEmpty);

    final SeatsEvent mixed = SeatsEvent.tryParse(
      event('seats.changed', <String, Object?>{
        'screening_id': 380,
        'version': 6,
        'seats': <String, Object?>{
          'held': <Object?>[890, 'x', null, 891],
          'sold': 'nie lista',
        },
      }),
    )!;

    // Z listy bierzemy tylko liczby; pole, które nie jest listą, pomijamy.
    expect(mixed.changes['held'], <int>[890, 891]);
    expect(mixed.changes.containsKey('sold'), isFalse);
  });
}
