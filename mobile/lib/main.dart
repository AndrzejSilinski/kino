// Punkt wejścia aplikacji mobilnej kina.
//
// Jedyne zadanie tego pliku: włączyć Riverpod (ProviderScope) i uruchomić
// CinemaApp. Logika siedzi w klientach API i providerach, a widgety tylko
// przyjmują dane i wołają metody (wymóg jakościowy zadania).

import 'package:cinema/app.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

void main() {
  runApp(const ProviderScope(retry: noRetry, child: CinemaApp()));
}
