// Korzeń aplikacji: motyw i nawigacja (go_router). Ekrany rejestruje
// lib/router.dart, a stan zalogowania trzyma AuthController.

import 'package:cinema/router.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class CinemaApp extends ConsumerWidget {
  const CinemaApp({super.key});

  /// Kolor wiodący ten sam co w SPA, żeby telefon i przeglądarka wyglądały
  /// jak jedna aplikacja.
  static const Color seedColor = Color(0xFF6D28D9);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'Kino',
      debugShowCheckedModeBanner: false,
      routerConfig: ref.watch(routerProvider),
      theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: seedColor)),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: seedColor,
          brightness: Brightness.dark,
        ),
      ),
    );
  }
}
