// Korzeń aplikacji. Nawigacja (go_router), motyw i teksty rozwijają się
// w bloku D; tutaj jest tylko tyle, ile potrzebuje ekran diagnostyczny.

import 'package:cinema/features/diagnostics/diagnostics_screen.dart';
import 'package:flutter/material.dart';

class CinemaApp extends StatelessWidget {
  const CinemaApp({super.key});

  /// Kolor wiodący ten sam co w SPA, żeby telefon i przeglądarka wyglądały
  /// jak jedna aplikacja.
  static const Color seedColor = Color(0xFF6D28D9);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Kino',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: seedColor)),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: seedColor,
          brightness: Brightness.dark,
        ),
      ),
      home: const DiagnosticsScreen(),
    );
  }
}
