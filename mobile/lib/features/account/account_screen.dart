// Ekran konta: zdjęcie, nazwa, hasło.
//
// Trzy rzeczy, których nie widać na pierwszy rzut oka:
//
// 1. Zdjęcie pokazujemy ZWYKŁYM adresem, bez nagłówka z tokenem — odwrotnie
//    niż kod QR biletu (decyzja 324). To nie niekonsekwencja: avatar leży
//    w publicznym magazynie pod losową nazwą z dwudziestu bajtów losowych,
//    więc adresu nie da się zgadnąć, a sam obraz nie jest przepustką do
//    niczego. Bilet jest przepustką na salę, więc tam token jest konieczny.
// 2. Każdy przycisk pilnuje SIEBIE: trwająca wysyłka zdjęcia nie wyłącza
//    zapisu nazwy, bo to dwie niezależne operacje. Blokada dotyczy tylko
//    równoległych żądań, które zużywałyby ten sam limit `account`.
// 3. Niezgodne powtórzenie hasła wyłapujemy przed wysłaniem (decyzja 338):
//    zmiana hasła ma osobny, ciasny limit, a literówkę widać bez serwera.

import 'dart:async';

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/photo_picker.dart';
import 'package:cinema/models/user.dart';
import 'package:cinema/state/account.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final User? user = ref.watch(authProvider).user;

    return Scaffold(
      appBar: AppBar(title: const Text('Moje konto')),
      body: user == null
          ? const _NotLoggedIn()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: <Widget>[
                const _Message(),
                _Avatar(user: user),
                const SizedBox(height: 24),
                _NameForm(user: user),
                const Divider(height: 40),
                const _PasswordForm(),
              ],
            ),
    );
  }
}

class _NotLoggedIn extends StatelessWidget {
  const _NotLoggedIn();

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(32),
      child: Text(
        'Zaloguj się, żeby zobaczyć dane swojego konta.',
        textAlign: TextAlign.center,
        style: Theme.of(context).textTheme.titleMedium,
      ),
    ),
  );
}

/// Jeden pasek na wszystkie komunikaty ekranu.
class _Message extends ConsumerWidget {
  const _Message();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AccountState state = ref.watch(accountProvider);
    final ApiError? error = state.error;
    final String? text = state.notice ?? state.problem ?? error?.message;
    if (text == null) {
      return const SizedBox.shrink();
    }
    final ColorScheme colors = Theme.of(context).colorScheme;
    final bool good = state.notice != null;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
      color: good ? colors.secondaryContainer : colors.errorContainer,
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                color: good
                    ? colors.onSecondaryContainer
                    : colors.onErrorContainer,
              ),
            ),
          ),
          IconButton(
            tooltip: 'Zamknij',
            icon: const Icon(Icons.close, size: 18),
            onPressed: ref.read(accountProvider.notifier).dismiss,
          ),
        ],
      ),
    );
  }
}

class _Avatar extends ConsumerWidget {
  const _Avatar({required this.user});

  final User user;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ThemeData theme = Theme.of(context);
    final AccountController controller = ref.read(accountProvider.notifier);
    final bool busy = ref.watch(accountProvider).busyWith(AccountJob.avatar);
    final String? avatar = user.avatarUrl;

    return Column(
      children: <Widget>[
        CircleAvatar(
          radius: 48,
          // Adres publiczny, bez tokenu — patrz komentarz na górze pliku.
          backgroundImage: avatar == null ? null : NetworkImage(avatar),
          // Bez tej obsługi nieudane pobranie obrazu wywraca drzewo widgetów.
          onBackgroundImageError: avatar == null
              ? null
              : (Object error, StackTrace? stack) {},
          child: avatar == null
              ? Text(user.initial, style: theme.textTheme.headlineMedium)
              : null,
        ),
        const SizedBox(height: 8),
        Text(user.name, style: theme.textTheme.titleLarge),
        Text(user.email, style: theme.textTheme.bodySmall),
        Text(user.roleLabel, style: theme.textTheme.bodySmall),
        const SizedBox(height: 12),
        if (busy)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          )
        else
          Wrap(
            alignment: WrapAlignment.center,
            spacing: 8,
            children: <Widget>[
              FilledButton.tonalIcon(
                onPressed: () =>
                    unawaited(controller.pickAvatar(PhotoOrigin.camera)),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('Zrób zdjęcie'),
              ),
              FilledButton.tonalIcon(
                onPressed: () =>
                    unawaited(controller.pickAvatar(PhotoOrigin.gallery)),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('Wybierz z galerii'),
              ),
              if (avatar != null)
                TextButton(
                  onPressed: () => unawaited(controller.removeAvatar()),
                  child: const Text('Usuń zdjęcie'),
                ),
            ],
          ),
      ],
    );
  }
}

class _NameForm extends ConsumerStatefulWidget {
  const _NameForm({required this.user});

  final User user;

  @override
  ConsumerState<_NameForm> createState() => _NameFormState();
}

class _NameFormState extends ConsumerState<_NameForm> {
  late final TextEditingController _name = TextEditingController(
    text: widget.user.name,
  );

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AccountState state = ref.watch(accountProvider);
    final bool busy = state.busyWith(AccountJob.name);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text('Nazwa', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        TextField(
          controller: _name,
          enabled: !busy,
          decoration: InputDecoration(
            labelText: 'Nazwa widoczna na koncie',
            // Błąd walidacji z serwera pokazujemy POD POLEM, którego dotyczy.
            errorText: state.error?.fieldError('name'),
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: busy
              ? null
              : () => unawaited(
                  ref.read(accountProvider.notifier).changeName(_name.text),
                ),
          child: Text(busy ? 'Zapisuję…' : 'Zapisz nazwę'),
        ),
      ],
    );
  }
}

class _PasswordForm extends ConsumerStatefulWidget {
  const _PasswordForm();

  @override
  ConsumerState<_PasswordForm> createState() => _PasswordFormState();
}

class _PasswordFormState extends ConsumerState<_PasswordForm> {
  final TextEditingController _current = TextEditingController();
  final TextEditingController _next = TextEditingController();
  final TextEditingController _repeat = TextEditingController();

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _repeat.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final bool ok = await ref
        .read(accountProvider.notifier)
        .changePasswordChecked(
          current: _current.text,
          next: _next.text,
          repeated: _repeat.text,
        );
    if (ok && mounted) {
      // Po udanej zmianie pola muszą być puste: zostawione hasło widać
      // każdemu, kto weźmie telefon do ręki.
      _current.clear();
      _next.clear();
      _repeat.clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    final AccountState state = ref.watch(accountProvider);
    final bool busy = state.busyWith(AccountJob.password);
    final ApiError? error = state.error;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text('Hasło', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        TextField(
          controller: _current,
          enabled: !busy,
          obscureText: true,
          decoration: InputDecoration(
            labelText: 'Obecne hasło',
            errorText: error?.fieldError('current_password'),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _next,
          enabled: !busy,
          obscureText: true,
          decoration: InputDecoration(
            labelText: 'Nowe hasło',
            helperText: 'Co najmniej 8 znaków, z literą i cyfrą.',
            errorText: error?.fieldError('password'),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _repeat,
          enabled: !busy,
          obscureText: true,
          decoration: const InputDecoration(labelText: 'Powtórz nowe hasło'),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: busy ? null : () => unawaited(_save()),
          child: Text(busy ? 'Zmieniam…' : 'Zmień hasło'),
        ),
      ],
    );
  }
}
