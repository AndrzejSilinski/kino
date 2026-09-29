// Nawigacja. Ścieżki są TE SAME co w SPA (decyzja 273): `/login`,
// `/register`, `/bookings/:reference`. Dzięki temu adres z powiadomienia
// push (`data.url`) otworzy w aplikacji ten sam ekran co w przeglądarce,
// bez tłumaczenia ścieżek (deep linki w bloku M).

import 'package:cinema/features/auth/login_screen.dart';
import 'package:cinema/features/auth/register_screen.dart';
import 'package:cinema/features/catalog/cinema_list_screen.dart';
import 'package:cinema/features/catalog/cinema_screen.dart';
import 'package:cinema/features/account/account_screen.dart';
import 'package:cinema/features/booking/checkout_screen.dart';
import 'package:cinema/features/bookings/booking_screen.dart';
import 'package:cinema/features/bookings/bookings_screen.dart';
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

  /// `/screenings/338/checkout` — podsumowanie i płatność.
  static String checkout(int id) => '${screening(id)}/checkout';

  /// `/account` — dane konta, zdjęcie i hasło.
  static const String account = '/account';

  /// `/bookings` — historia zakupów.
  static const String bookings = '/bookings';

  /// `/bookings/01M2R0FF9TF8JNNQ9GBNZJ3TDQ` — jedna rezerwacja z biletami.
  /// Kluczem jest ULID, nie sekwencyjne id: po numerach dałoby się skanować
  /// cudze zakupy, a ten sam adres działa w SPA i w linku z powiadomienia.
  static String booking(String reference) => '$bookings/$reference';

  /// Czy aplikacja ma ekran pod tym adresem (decyzja 355).
  ///
  /// To ŚWIADOMA druga kopia wzorców z tras poniżej — i tak, kopia kosztuje.
  /// Alternatywą byłoby zapytanie routera, czy adres da się dopasować, ale
  /// `GoRouter` odpowiada na to dopiero nawigacją: przy nietrafionym adresie
  /// pokazuje własny ekran błędu, czyli robi dokładnie to, czego chcemy uniknąć
  /// po kliknięciu w powiadomienie. Kopii pilnuje test: liczy trasy
  /// zadeklarowane niżej i nie przepuści dodania nowej bez uzupełnienia tej
  /// metody.
  ///
  /// Serwer wysyła dziś w powiadomieniach wyłącznie `/bookings/{reference}`
  /// (`BookingPushContent::url`), ale obsługujemy wszystkie adresy aplikacji,
  /// bo ten sam kod obsłuży też zwykły link otwarty na telefonie.
  static bool knows(String path) {
    const List<String> stale = <String>[
      home,
      login,
      register,
      cinemas,
      account,
      bookings,
      diagnostics,
    ];
    return stale.contains(path) ||
        _cinemaPath.hasMatch(path) ||
        _screeningPath.hasMatch(path) ||
        _bookingPath.hasMatch(path);
  }

  static final RegExp _cinemaPath = RegExp(r'^/cinemas/[a-z0-9-]+$');
  static final RegExp _screeningPath = RegExp(
    r'^/screenings/\d+(/seats|/checkout)?$',
  );
  static final RegExp _bookingPath = RegExp(
    r'^/bookings/[0-9A-HJKMNP-TV-Z]{26}$',
  );
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
        // Ten sam wzorzec identyfikatora co wyżej: ekran płatności jest
        // podścieżką seansu, bo checkout to podzasób seansu w API.
        path: '/screenings/:id(\\d+)/checkout',
        builder: (BuildContext context, GoRouterState state) =>
            CheckoutScreen(screeningId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: Routes.account,
        builder: (BuildContext context, GoRouterState state) =>
            const AccountScreen(),
      ),
      GoRoute(
        path: Routes.bookings,
        builder: (BuildContext context, GoRouterState state) =>
            const BookingsScreen(),
      ),
      GoRoute(
        // Wzorzec ULID-a: 26 znaków z alfabetu Crockforda, wielkie litery.
        // Adres z powiadomienia albo z linku nie wpuści tu czegoś innego.
        path: '${Routes.bookings}/:reference([0-9A-HJKMNP-TV-Z]{26})',
        builder: (BuildContext context, GoRouterState state) =>
            BookingScreen(reference: state.pathParameters['reference']!),
      ),
      GoRoute(
        path: Routes.diagnostics,
        builder: (BuildContext context, GoRouterState state) =>
            const DiagnosticsScreen(),
      ),
    ],
  );
});
