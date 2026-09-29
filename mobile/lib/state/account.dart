// Stan konta: zmiana nazwy, zmiana hasła, avatar.
//
// Decyzja 331: dane zalogowanego konta mają JEDNO miejsce — stan logowania.
// Ten notifier trzyma tylko to, co dotyczy trwającej operacji (czy coś idzie,
// jaki komunikat pokazać), a każdą udaną zmianę wpisuje do `authProvider`
// pełnym profilem z odpowiedzi serwera. Gdyby trzymał własną kopię
// użytkownika, ekran konta i kółko z inicjałem w nagłówku pokazywałyby po
// zmianie nazwy dwie różne rzeczy — i to jest błąd, którego nie widać
// w testach jednego ekranu.
//
// Odpowiedzi zmian niosą pełny `UserResource`, więc po żadnej z nich NIE
// pytamy serwera o `/auth/me`. Przy limicie `account` liczonym per użytkownik
// drugie żądanie po to samo zużywałoby limit bez powodu.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/photo_picker.dart';
import 'package:cinema/data/account_repository.dart';
import 'package:cinema/models/user.dart';
// `authProvider` mieszka w providers.dart, a `AuthController` dostajemy przez
// `.notifier` bez nazywania typu — import state/auth.dart byłby tu zbędny.
import 'package:cinema/state/providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final Provider<AccountRepository> accountRepositoryProvider =
    Provider<AccountRepository>(
      (Ref ref) => AccountRepository(ref.watch(apiClientProvider)),
    );

/// Aparat i galeria. Podmieniane w testach na atrapę (decyzja 335).
final Provider<PhotoPicker> photoPickerProvider = Provider<PhotoPicker>(
  (Ref ref) => const SystemPhotoPicker(),
);

/// Co dzieje się na ekranie konta.
enum AccountJob { none, name, password, avatar }

class AccountState {
  const AccountState({
    this.job = AccountJob.none,
    this.notice,
    this.problem,
    this.error,
  });

  /// Trwająca operacja — ekran wyłącza wtedy TEN przycisk, a nie wszystkie.
  final AccountJob job;

  /// Komunikat o powodzeniu, z serwera albo krótki własny.
  final String? notice;

  /// Kłopot, o którym wie SAMA APLIKACJA: odmowa systemu, niezgodne hasła,
  /// nieudane przetworzenie zdjęcia. Trzymany osobno od `error`, bo tamten
  /// przychodzi z serwera i niesie `errors` dla konkretnych pól — a mieszanie
  /// tych dwóch źródeł w jednym polu kończy się komunikatem, o którym nie
  /// wiadomo, czy da się coś z nim zrobić.
  final String? problem;

  /// Błąd ostatniej operacji. Zostaje w stanie, bo przy zmianie hasła niesie
  /// `errors` dla konkretnych pól i ekran umie je pokazać pod polem.
  final ApiError? error;

  bool get busy => job != AccountJob.none;

  bool busyWith(AccountJob which) => job == which;
}

class AccountController extends Notifier<AccountState> {
  @override
  AccountState build() => const AccountState();

  AccountRepository get _repo => ref.read(accountRepositoryProvider);

  Future<bool> changeName(String name) => _run(
    AccountJob.name,
    () async => _applied(await _repo.updateName(name.trim())),
    'Nazwa została zmieniona.',
  );

  /// Zmiana hasła. Komunikat składamy z odpowiedzi serwera, bo liczba
  /// wylogowanych urządzeń jest informacją, na której klientowi zależy.
  Future<bool> changePassword({
    required String current,
    required String next,
  }) async {
    if (state.busy) {
      return false;
    }
    state = const AccountState(job: AccountJob.password);
    try {
      final PasswordChanged result = await _repo.changePassword(
        current: current,
        next: next,
      );
      state = AccountState(notice: _passwordNotice(result));
      return true;
    } on ApiError catch (error) {
      state = AccountState(error: error);
      return false;
    }
  }

  Future<bool> uploadAvatar(List<int> bytes) => _run(
    AccountJob.avatar,
    () async => _applied(await _repo.uploadAvatar(bytes: bytes)),
    'Zdjęcie zostało zapisane.',
  );

  Future<bool> removeAvatar() => _run(
    AccountJob.avatar,
    () async => _applied(await _repo.removeAvatar()),
    'Zdjęcie zostało usunięte.',
  );

  /// Zdjęcie z aparatu albo z galerii: wybór, zmniejszenie i wysłanie.
  Future<bool> pickAvatar(PhotoOrigin origin) async {
    if (state.busy) {
      return false;
    }
    state = const AccountState(job: AccountJob.avatar);
    final PhotoOutcome outcome = await ref
        .read(photoPickerProvider)
        .take(origin);
    final PickedPhoto? photo = outcome.photo;
    if (outcome.result == PhotoResult.picked && photo != null) {
      // Blokadę zwalniamy przed wysyłką, bo `uploadAvatar` zakłada ją sam.
      state = const AccountState();
      return uploadAvatar(photo.bytes);
    }
    state = AccountState(problem: _photoProblem(outcome.result));
    return false;
  }

  /// Komunikaty aplikacji, nie serwera — więc piszemy je tak, żeby mówiły,
  /// CO ZROBIĆ. „Coś się nie udało" zostawia użytkownika bez wyjścia.
  static String? _photoProblem(PhotoResult result) => switch (result) {
    PhotoResult.picked || PhotoResult.cancelled => null,
    PhotoResult.denied =>
      'System nie dał dostępu do zdjęć. Zgodę można włączyć w ustawieniach '
          'telefonu, w uprawnieniach aplikacji.',
    PhotoResult.failed =>
      'Nie udało się przygotować zdjęcia. Spróbuj wybrać inne albo zrobić '
          'nowe.',
  };

  /// Zmiana hasła z kontrolą po stronie aplikacji.
  ///
  /// Niezgodne powtórzenie hasła wyłapujemy U SIEBIE i nie wysyłamy żądania:
  /// zmiana hasła ma osobny, ciasny limit, a literówka w powtórzeniu jest
  /// rzeczą, którą aplikacja widzi bez pytania serwera.
  Future<bool> changePasswordChecked({
    required String current,
    required String next,
    required String repeated,
  }) async {
    if (next != repeated) {
      state = const AccountState(
        problem: 'Powtórzone hasło różni się od nowego.',
      );
      return false;
    }
    return changePassword(current: current, next: next);
  }

  void dismiss() {
    if (state.notice == null && state.problem == null && state.error == null) {
      return;
    }
    state = const AccountState();
  }

  User _applied(User user) {
    ref.read(authProvider.notifier).applyUser(user);
    return user;
  }

  static String _passwordNotice(PasswordChanged result) =>
      result.revokedTokens == 0
      ? result.message
      : '${result.message} Wylogowano ${result.revokedTokens} '
            '${result.revokedTokens == 1 ? "inne urządzenie" : "innych urządzeń"}.';

  Future<bool> _run(
    AccountJob job,
    Future<User> Function() action,
    String notice,
  ) async {
    if (state.busy) {
      return false;
    }
    state = AccountState(job: job);
    try {
      await action();
      state = AccountState(notice: notice);
      return true;
    } on ApiError catch (error) {
      state = AccountState(error: error);
      return false;
    }
  }
}

final NotifierProvider<AccountController, AccountState> accountProvider =
    NotifierProvider<AccountController, AccountState>(AccountController.new);
