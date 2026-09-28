// Wspólny odczyt fikstur z prawdziwych odpowiedzi serwera (rozpoznanie).

import 'dart:convert';
import 'dart:io';

Map<String, Object?> envelope(String name) =>
    jsonDecode(File('test/fixtures/$name.json').readAsStringSync())
        as Map<String, Object?>;

/// Zawartość koperty `data` jako lista.
List<Object?> fixtureList(String name) =>
    envelope(name)['data']! as List<Object?>;

/// Zawartość koperty `data` jako mapa.
Map<String, Object?> fixtureMap(String name) =>
    envelope(name)['data']! as Map<String, Object?>;
