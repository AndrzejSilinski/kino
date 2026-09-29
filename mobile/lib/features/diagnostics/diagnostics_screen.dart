// Ekran diagnostyczny — pierwszy realny dowód, że telefon widzi API.
//
// Po co osobny ekran: łączy trzy rzeczy, które w bloku C sprawdzamy na żywo —
// adres z `--dart-define`, ruch HTTP bez TLS z gniazd Darta i kształt koperty
// `data`. Ścieżka zakupowa wchodzi od bloku E; ten ekran zostaje jako pomoc
// przy pracy z telefonem, bo widać na nim, do jakiego serwera mówi APK.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/app_config.dart';
import 'package:cinema/core/push.dart';
import 'package:cinema/models/client_config.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class DiagnosticsScreen extends ConsumerWidget {
  const DiagnosticsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AppConfig config = ref.watch(appConfigProvider);
    final AsyncValue<ClientConfig> state = ref.watch(clientConfigProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Kino — diagnostyka')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(clientConfigProvider.future),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: <Widget>[
            _Row(label: 'Adres API', value: config.apiBaseUrl.toString()),
            _Row(
              label: 'Połączenie bez TLS',
              value: config.isCleartext ? 'tak (tylko build dev)' : 'nie',
            ),
            const Divider(height: 32),
            state.when(
              data: (ClientConfig loaded) => _Loaded(config: loaded),
              error: (Object error, StackTrace stackTrace) => _Failed(
                error: error,
                onRetry: () => ref.invalidate(clientConfigProvider),
              ),
              loading: () => const Padding(
                padding: EdgeInsets.symmetric(vertical: 32),
                child: Center(child: CircularProgressIndicator()),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Loaded extends StatelessWidget {
  const _Loaded({required this.config});

  final ClientConfig config;

  @override
  Widget build(BuildContext context) {
    final BookingConfig booking = config.booking;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(
          'Połączenie z API działa',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        _Row(label: 'Wersja API', value: config.apiVersion),
        _Row(
          label: 'WebSocket',
          value:
              '${config.realtime.broadcaster}, '
              'ścieżka ${config.realtime.path}, '
              'klucz ${config.realtime.key.length} znaków',
        ),
        _Row(
          label: 'Blokada miejsca',
          value: '${booking.seatLockTtl.inSeconds} s',
        ),
        _Row(
          label: 'Limit miejsc w sesji',
          value: '${booking.maxSeatsPerSession}',
        ),
        _Row(
          label: 'Okno płatności',
          value: '${booking.paymentWindow.inSeconds} s',
        ),
        _Row(
          label: 'Push włączony na serwerze',
          value: config.pushEnabled ? 'tak' : 'nie',
        ),
        _FirebaseCheck(server: config.android),
      ],
    );
  }
}

/// Porównanie projektu Firebase aplikacji z projektem serwera (decyzja 354).
///
/// To jedyne miejsce w całym systemie, gdzie tę pomyłkę w ogóle widać.
/// Aplikacja zbudowana z `google-services.json` innego projektu niż ten,
/// z którego wysyła serwer, zarejestruje urządzenie bez błędu i dostanie
/// poprawnie wyglądający token — a powiadomienia nigdy nie dojdą, bo token
/// z jednego projektu jest w drugim nieznany. Serwer też się nie dowie:
/// zobaczy odpowiedź „nieznany token" i skasuje urządzenie, czyli zachowa się
/// dokładnie tak jak przy odinstalowanej aplikacji.
class _FirebaseCheck extends ConsumerWidget {
  const _FirebaseCheck({required this.server});

  final AndroidPushConfig? server;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Publiczne pole klasy nie podlega promocji typu — stąd kopia lokalna.
    final AndroidPushConfig? expected = server;
    final AsyncValue<PushProject?> app = ref.watch(pushProjectProvider);
    // `when` zamiast skrótów na `AsyncValue`: tak samo jak wyżej w tym pliku,
    // i tak samo widać tu wszystkie trzy stany, łącznie z błędem.
    final PushProject? built = app.when(
      data: (PushProject? value) => value,
      error: (Object error, StackTrace stack) => null,
      loading: () => null,
    );
    final String label = app.when(
      data: (PushProject? value) =>
          value?.projectId ?? 'brak — APK bez google-services.json',
      error: (Object error, StackTrace stack) => 'nie udało się odczytać',
      loading: () => 'sprawdzam…',
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        _Row(
          label: 'Projekt Firebase (serwer)',
          value: expected?.projectId ?? 'nie podany w konfiguracji',
        ),
        _Row(label: 'Projekt Firebase (aplikacja)', value: label),
        if (expected != null &&
            built != null &&
            expected.projectId != built.projectId)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              'Aplikację zbudowano z INNEGO projektu Firebase niż ten, '
              'z którego wysyła serwer. Rejestracja urządzenia przejdzie, '
              'ale powiadomienia nie dojdą.',
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
      ],
    );
  }
}

class _Failed extends StatelessWidget {
  const _Failed({required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    // Komunikat bierzemy z serwera (jest po polsku i gotowy do wyświetlenia),
    // a kod pokazujemy osobno: aplikacja rozgałęzia się po kodzie, więc przy
    // pracy z telefonem chcemy go mieć na ekranie.
    // Publiczne pole klasy nie podlega promocji typu, więc najpierw kopia lokalna.
    final Object thrown = error;
    final ApiError? apiError = thrown is ApiError ? thrown : null;
    final String message =
        apiError?.message ?? 'Nie udało się pobrać konfiguracji.';
    final String code = apiError?.code ?? error.runtimeType.toString();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(message, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Text('Kod: $code'),
        const SizedBox(height: 16),
        FilledButton(onPressed: onRetry, child: const Text('Spróbuj ponownie')),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(
            width: 160,
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}
