// Punkt wejścia aplikacji mobilnej kina.
//
// Dwa zadania: włączyć Riverpod (ProviderScope) i uruchomić CinemaApp. Logika
// siedzi w klientach API i providerach, a widgety tylko przyjmują dane i wołają
// metody (wymóg jakościowy zadania).
//
// Od bloku M3 dochodzi trzecie: przygotowanie warstwy powiadomień, zanim
// cokolwiek jej użyje. Dzieje się to TU, a nie w providerze, bo `Firebase`
// trzeba zainicjować raz i przed `runApp`. Przy okazji nic się nie psuje
// w testach: `main()` w nich nie działa, więc pod `pushServiceProvider`
// zostaje atrapa z providers.dart.

import 'package:cinema/app.dart';
import 'package:cinema/core/firebase_push.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

Future<void> main() async {
  // Wtyczki natywne rozmawiają przez kanały platformy, a te wymagają gotowego
  // wiązania. Bez tej linii `Firebase.initializeApp()` przed `runApp` pada.
  WidgetsFlutterBinding.ensureInitialized();

  // Nie przerywa startu, gdy Firebase nie daje się zainicjować — oddaje wtedy
  // atrapę, a aplikacja działa dalej, tylko bez powiadomień (decyzja 358).
  final PushService push = await startPush();

  runApp(
    ProviderScope(
      retry: noRetry,
      overrides: [pushServiceProvider.overrideWithValue(push)],
      child: const CinemaApp(),
    ),
  );
}
