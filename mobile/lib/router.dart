// Nawigacja. Ścieżki są TE SAME co w SPA (decyzja 273): `/login`,
// `/register`, `/bookings/:reference`. Dzięki temu adres z powiadomienia
// push (`data.url`) otworzy w aplikacji ten sam ekran co w przeglądarce,
// bez tłumaczenia ścieżek (deep linki w bloku M).

import 'package:cinema/features/auth/login_screen.dart';
import 'package:cinema/features/auth/register_screen.dart';
import 'package:cinema/features/diagnostics/diagnostics_screen.dart';
import 'package:cinema/features/home/home_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Ścieżki w jednym miejscu — używa ich router, ekrany i walidator adresów
/// z powiadomień.
class Routes {
  const Routes._();

  static const String home = '/';
  static const String login = '/login';
  static const String register = '/register';
  static const String diagnostics = '/diagnostics';
}

final Provider<GoRouter> routerProvider = Provider<GoRouter>((Ref ref) {
  return GoRouter(
    initialLocation: Routes.home,
    routes: <RouteBase>[
      GoRoute(
        path: Routes.home,
        builder: (BuildContext context, GoRouterState state) =>
            const HomeScreen(),
      ),
      GoRoute(
        path: Routes.login,
        builder: (BuildContext context, GoRouterState state) =>
            const LoginScreen(),
      ),
      GoRoute(
        path: Routes.register,
        builder: (BuildContext context, GoRouterState state) =>
            const RegisterScreen(),
      ),
      GoRoute(
        path: Routes.diagnostics,
        builder: (BuildContext context, GoRouterState state) =>
            const DiagnosticsScreen(),
      ),
    ],
  );
});
