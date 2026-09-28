// Ekran startowy. W bloku E stanie się listą kin; na razie pokazuje stan
// zalogowania i prowadzi do logowania oraz do diagnostyki.

import 'package:cinema/models/user.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/catalog.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AuthState auth = ref.watch(authProvider);
    // Zapamiętane kino prowadzi wprost do repertuaru; bez niego zaczynamy
    // od listy miast (decyzja 279).
    final String? cinema = ref.watch(selectedCinemaProvider);
    final String catalogPath = cinema == null
        ? Routes.cinemas
        : Routes.cinema(cinema);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Kino'),
        actions: <Widget>[
          IconButton(
            onPressed: () => context.go(Routes.diagnostics),
            icon: const Icon(Icons.info_outline),
            tooltip: 'Diagnostyka połączenia',
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: <Widget>[
            Expanded(
              child: switch (auth.status) {
                AuthStatus.unknown => const Center(
                  child: CircularProgressIndicator(),
                ),
                AuthStatus.anonymous => _Anonymous(busy: auth.busy),
                AuthStatus.authenticated => _Authenticated(
                  user: auth.user,
                  busy: auth.busy,
                  onLogout: () => ref.read(authProvider.notifier).logout(),
                ),
              },
            ),
            FilledButton.tonal(
              onPressed: () => context.go(catalogPath),
              child: const Text('Repertuar i bilety'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Anonymous extends StatelessWidget {
  const _Anonymous({required this.busy});

  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisAlignment: MainAxisAlignment.center,
      children: <Widget>[
        Text(
          'Zaloguj się, żeby kupić bilety i mieć je zawsze przy sobie.',
          style: Theme.of(context).textTheme.titleMedium,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: busy ? null : () => context.go(Routes.login),
          child: const Text('Zaloguj się'),
        ),
        const SizedBox(height: 8),
        OutlinedButton(
          onPressed: busy ? null : () => context.go(Routes.register),
          child: const Text('Załóż konto'),
        ),
      ],
    );
  }
}

class _Authenticated extends StatelessWidget {
  const _Authenticated({
    required this.user,
    required this.busy,
    required this.onLogout,
  });

  final User? user;
  final bool busy;
  final VoidCallback onLogout;

  @override
  Widget build(BuildContext context) {
    final User? account = user;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisAlignment: MainAxisAlignment.center,
      children: <Widget>[
        if (account != null) ...<Widget>[
          Center(
            child: CircleAvatar(
              radius: 32,
              child: Text(
                account.initial,
                style: Theme.of(context).textTheme.headlineMedium,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'Cześć, ${account.name}!',
            style: Theme.of(context).textTheme.titleLarge,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 4),
          Text(
            account.email,
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ],
        const SizedBox(height: 24),
        OutlinedButton(
          onPressed: busy ? null : onLogout,
          child: Text(busy ? 'Wylogowywanie…' : 'Wyloguj się'),
        ),
      ],
    );
  }
}
