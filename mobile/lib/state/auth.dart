// Stan zalogowania: jedno źródło prawdy dla ekranów i nawigacji.

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/session.dart';
import 'package:cinema/data/auth_repository.dart';
import 'package:cinema/models/user.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

enum AuthStatus {
  /// Start aplikacji: czytamy token z magazynu i pytamy serwer, kim jesteśmy.
  unknown,
  anonymous,
  authenticated,
}

class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.error,
    this.busy = false,
  });

  final AuthStatus status;
  final User? user;

  /// Ostatni błąd logowania albo rejestracji — ekran czyta z niego `code`
  /// oraz komunikaty pól (422).
  final ApiError? error;

  /// Trwa żądanie: przyciski formularza są wtedy zablokowane.
  final bool busy;

  bool get isAuthenticated => status == AuthStatus.authenticated;
}

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    final AppSession session = ref.watch(sessionProvider);
    // 401 UNAUTHENTICATED z dowolnego żądania: token wygasł albo został
    // unieważniony (zmiana hasła w SPA kasuje pozostałe sesje).
    session.onRejected = _onTokenRejected;
    ref.onDispose(() => session.onRejected = null);
    unawaited(restore());
    return const AuthState(status: AuthStatus.unknown);
  }

  AuthRepository get _repository => ref.read(authRepositoryProvider);

  AppSession get _session => ref.read(sessionProvider);

  /// Start aplikacji: token z Keystore, potem potwierdzenie na serwerze.
  Future<void> restore() async {
    await _session.restore();
    // Użytkownik mógł w międzyczasie zalogować się ręcznie (odczyt z Keystore
    // trwa kilkadziesiąt milisekund) — wtedy nie nadpisujemy świeższego stanu.
    if (state.isAuthenticated) {
      return;
    }
    if (_session.token == null) {
      state = const AuthState(status: AuthStatus.anonymous);
      return;
    }
    try {
      final User user = await _repository.me();
      state = AuthState(status: AuthStatus.authenticated, user: user);
    } on ApiError catch (error) {
      // Token odrzucony: klient API już go wyczyścił. Brak sieci: zostajemy
      // przy stanie wylogowanym, bo bez serwera i tak nic nie pokażemy.
      state = AuthState(
        status: AuthStatus.anonymous,
        error: error.code == ApiError.unauthenticated ? null : error,
      );
    }
  }

  Future<bool> login({required String email, required String password}) =>
      _run(() => _repository.login(email: email, password: password));

  Future<bool> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) => _run(
    () => _repository.register(
      name: name,
      email: email,
      password: password,
      passwordConfirmation: passwordConfirmation,
    ),
  );

  /// Wylogowanie jest lokalne nawet wtedy, gdy serwer nie odpowiada: token
  /// znika z telefonu, a na serwerze wygaśnie sam po 30 dniach.
  Future<void> logout() async {
    state = AuthState(status: state.status, user: state.user, busy: true);
    try {
      await _repository.logout();
    } on ApiError {
      // Brak sieci albo token już nieważny — i tak czyścimy stan lokalny.
    }
    await _session.clearToken();
    state = const AuthState(status: AuthStatus.anonymous);
  }

  Future<bool> _run(Future<AuthResult> Function() action) async {
    state = AuthState(status: state.status, user: state.user, busy: true);
    try {
      final AuthResult result = await action();
      await _session.setToken(result.token);
      state = AuthState(status: AuthStatus.authenticated, user: result.user);
      return true;
    } on ApiError catch (error) {
      state = AuthState(status: AuthStatus.anonymous, error: error);
      return false;
    }
  }

  void _onTokenRejected() {
    state = const AuthState(status: AuthStatus.anonymous);
  }
}
