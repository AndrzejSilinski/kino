// Nawigacja. Ścieżki są TE SAME co w SPA (decyzja 273): `/login`,
// `/register`, `/bookings/:reference`. Dzięki temu adres z powiadomienia
// push (`data.url`) otworzy w aplikacji ten sam ekran co w przeglądarce,
// bez tłumaczenia ścieżek (deep linki w bloku M).

import 'package:cinema/features/auth/login_screen.dart';
import 'package:cinema/features/auth/register_screen.dart';
import 'package:cinema/features/catalog/cinema_list_screen.dart';
import 'package:cinema/features/catalog/cinema_screen.dart';
import 'package:cinema/features/booking/seat_map_screen.dart';
import 'package:cinema/features/catalog/screening_screen.dart';
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
  static const String cinemas = '/cinemas';
  static const String diagnostics = '/diagnostics';

  /// `/cinemas/gdansk-kino-baltyk` — ta sama postać co w SPA.
  static String cinema(String slug) => '$cinemas/$slug';

  /// `/screenings/338`
  static String screening(int id) => '/screenings/$id';

  /// `/screenings/338/seats` — wybór miejsc na seansie.
  static String seats(int id) => '${screening(id)}/seats';
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
        path: Routes.cinemas,
        builder: (BuildContext context, GoRouterState state) =>
            const CinemaListScreen(),
      ),
      GoRoute(
        // Parametr sluga ograniczony wzorcem — adres z powiadomienia albo
        // z linku nie wpuści do aplikacji czegoś, co nie jest slugiem.
        path: '${Routes.cinemas}/:slug([a-z0-9-]+)',
        builder: (BuildContext context, GoRouterState state) =>
            CinemaScreen(slug: state.pathParameters['slug']!),
      ),
      GoRoute(
        path: '/screenings/:id(\\d+)',
        builder: (BuildContext context, GoRouterState state) =>
            ScreeningScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        // Podścieżka wyboru miejsc trzyma się tego samego wzorca co seans:
        // identyfikator tylko z cyfr, więc adres z linku nie wpuści śmieci.
        path: '/screenings/:id(\\d+)/seats',
        builder: (BuildContext context, GoRouterState state) =>
            SeatMapScreen(screeningId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: Routes.diagnostics,
        builder: (BuildContext context, GoRouterState state) =>
            const DiagnosticsScreen(),
      ),
    ],
  );
});
