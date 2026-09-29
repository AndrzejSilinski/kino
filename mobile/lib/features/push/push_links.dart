// Co się dzieje, gdy powiadomienie przychodzi albo ktoś w nie klika.
//
// Widget opakowuje całą aplikację (`MaterialApp.router` → `builder`), bo musi
// żyć tak długo jak ona: powiadomienie może przyjść na dowolnym ekranie i po
// kliknięciu ma zaprowadzić na WŁAŚCIWY, niezależnie od tego, gdzie użytkownik
// był wcześniej.
//
// Trzy stany aplikacji, trzy różne drogi — i to nie jest komplikacja na zapas,
// tylko tak działa system:
//   * aplikacja ZAMKNIĘTA — powiadomienie pokazał system, kliknięcie uruchamia
//     aplikację, a wiadomość czeka w `launchNotification()` (do odczytu raz),
//   * aplikacja W TLE — pokazał system, kliknięcie wraca do działającej
//     aplikacji, wiadomość przychodzi strumieniem `opened`,
//   * aplikacja NA PIERWSZYM PLANIE — system NIE pokazuje nic (tak działa FCM),
//     więc powiadomienie musimy pokazać sami: strumień `foreground`.

import 'dart:async';

import 'package:cinema/core/push.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class PushLinks extends ConsumerStatefulWidget {
  const PushLinks({required this.child, super.key});

  final Widget child;

  @override
  ConsumerState<PushLinks> createState() => _PushLinksState();
}

class _PushLinksState extends ConsumerState<PushLinks> {
  StreamSubscription<PushNotification>? _opened;
  StreamSubscription<PushNotification>? _foreground;

  @override
  void initState() {
    super.initState();
    final PushService service = ref.read(pushServiceProvider);
    _opened = service.opened.listen(_open);
    _foreground = service.foreground.listen(_show);
    unawaited(_start(service));
  }

  @override
  void dispose() {
    unawaited(_opened?.cancel());
    unawaited(_foreground?.cancel());
    super.dispose();
  }

  /// Start: kanał powiadomień i wiadomość, którą uruchomiono aplikację.
  Future<void> _start(PushService service) async {
    await service.prepare();
    final PushNotification? launch = await service.launchNotification();
    if (launch != null) {
      _open(launch);
    }
  }

  /// Kliknięcie w powiadomienie.
  ///
  /// Decyzja 355: idziemy TYLKO tam, gdzie na pewno jest ekran. `appPath`
  /// (decyzja 351) sprawdza kształt adresu, ale kształt to nie istnienie trasy:
  /// poprawna ścieżka `/nieistniejace` przeszłaby walidację i skończyła się
  /// ekranem błędu routera — czyli po kliknięciu w powiadomienie użytkownik
  /// zobaczyłby komunikat o błędzie zamiast aplikacji. Nieznany adres znaczy
  /// więc „po prostu otwórz aplikację", co jest najgorszym wynikiem, jaki może
  /// mieć kliknięcie w powiadomienie — i tak ma być.
  void _open(PushNotification notification) {
    final String? path = notification.path;
    if (path == null || !Routes.knows(path)) {
      return;
    }
    ref.read(routerProvider).go(path);
  }

  /// Powiadomienie przy aplikacji na pierwszym planie.
  ///
  /// Wiadomość bez tytułu i treści (sama sekcja `data`) nie ma czego pokazać —
  /// puste powiadomienie byłoby gorsze niż żadne.
  void _show(PushNotification notification) {
    if (!notification.hasText) {
      return;
    }
    unawaited(ref.read(pushServiceProvider).display(notification));
  }

  @override
  Widget build(BuildContext context) {
    // Po zalogowaniu odświeżamy rejestrację urządzenia — ale tylko tam, gdzie
    // push już włączono (decyzja 353). Token mógł się zmienić, gdy aplikacja
    // nie działała, a wtedy powiadomienia po cichu przestają przychodzić.
    ref.listen(authProvider, (AuthState? previous, AuthState next) {
      if (next.isAuthenticated && previous?.isAuthenticated != true) {
        unawaited(ref.read(pushProvider.notifier).refreshIfRegistered());
      }
    });
    return widget.child;
  }
}
