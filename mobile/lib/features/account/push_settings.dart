// Powiadomienia na tym telefonie — sekcja ekranu konta.
//
// Osobny plik, bo ekran konta jest już długi, a ta sekcja ma własny stan
// i własne żądania (zgoda systemu, zgoda na koncie, rejestracja urządzenia).
//
// Sześć stanów, nie dwa. Kusi, żeby pokazać jeden przełącznik „powiadomienia
// wł./wył.", ale wtedy telefon bez Usług Google, kino bez skonfigurowanej
// wysyłki i odmowa systemu wyglądałyby identycznie: przełącznik, który po
// naciśnięciu wraca na miejsce. Użytkownik nie ma wtedy żadnej informacji,
// co zrobić — a w dwóch z tych przypadków nie da się zrobić nic, i to też
// jest odpowiedź (decyzja 356).

import 'dart:async';

import 'package:cinema/state/providers.dart';
import 'package:cinema/state/push.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class PushSettings extends ConsumerStatefulWidget {
  const PushSettings({super.key});

  @override
  ConsumerState<PushSettings> createState() => _PushSettingsState();
}

class _PushSettingsState extends ConsumerState<PushSettings> {
  @override
  void initState() {
    super.initState();
    // Stan poznajemy dopiero na ekranie: pytanie systemu o zgodę i serwera
    // o konfigurację przy każdym starcie aplikacji byłoby robotą dla wszystkich
    // po to, żeby zobaczył ją ten jeden, który wszedł na konto.
    //
    // PO KLATCE, nie w trakcie budowania drzewa: `check()` ustawia stan
    // providera już pierwszą instrukcją, a zmiana stanu w `initState`
    // unieważniłaby widget w czasie jego własnego budowania — Riverpod słusznie
    // by na to nakrzyczał (pułapka EA, ta sama co w historii zakupów).
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        unawaited(ref.read(pushProvider.notifier).check());
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final PushState state = ref.watch(pushProvider);
    final ThemeData theme = Theme.of(context);
    // Publiczne pole klasy nie podlega promocji typu — stąd kopia lokalna,
    // tak samo jak na ekranie diagnostycznym.
    final String? note = state.message;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text('Powiadomienia', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(_describe(state.status), style: theme.textTheme.bodyMedium),
        if (note != null) ...<Widget>[
          const SizedBox(height: 8),
          _Note(text: note, onClose: ref.read(pushProvider.notifier).dismiss),
        ],
        const SizedBox(height: 12),
        if (state.busy || state.status == PushStatus.checking)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          )
        else if (state.status == PushStatus.off)
          FilledButton.tonalIcon(
            onPressed: () =>
                unawaited(ref.read(pushProvider.notifier).enable()),
            icon: const Icon(Icons.notifications_active_outlined),
            label: const Text('Włącz powiadomienia'),
          )
        else if (state.isOn)
          TextButton.icon(
            onPressed: () =>
                unawaited(ref.read(pushProvider.notifier).disable()),
            icon: const Icon(Icons.notifications_off_outlined),
            label: const Text('Wyłącz na tym telefonie'),
          ),
      ],
    );
  }

  /// Opis stanu. Każdy mówi, CO DALEJ — albo wprost, że nic się nie da zrobić.
  String _describe(PushStatus status) => switch (status) {
    PushStatus.checking => 'Sprawdzam…',
    PushStatus.unavailable =>
      'Kino nie wysyła teraz powiadomień. O płatności i zmianach '
          'w seansie dostaniesz e-mail.',
    PushStatus.unsupported =>
      'To urządzenie nie obsługuje powiadomień push. E-mail przychodzi '
          'niezależnie od tego.',
    PushStatus.denied =>
      'Powiadomienia są zablokowane w ustawieniach telefonu. Włącz je '
          'w ustawieniach systemu dla aplikacji Kino — stąd nie da się '
          'o nie poprosić jeszcze raz.',
    PushStatus.off =>
      'Możesz dostawać na tym telefonie potwierdzenia płatności, '
          'przypomnienia o seansie i informacje o odwołanym seansie.',
    PushStatus.on =>
      'Włączone na tym telefonie. Wyłączenie tutaj nie dotyczy innych '
          'urządzeń — zgoda na koncie zostaje.',
  };
}

/// Komunikat sekcji. Osobny od paska ekranu konta, bo dotyczy tylko tego
/// fragmentu i nie ma powodu, żeby kasował komunikat o zapisanym zdjęciu.
class _Note extends StatelessWidget {
  const _Note({required this.text, required this.onClose});

  final String text;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 4, 4, 4),
      color: colors.errorContainer,
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(text, style: TextStyle(color: colors.onErrorContainer)),
          ),
          IconButton(
            tooltip: 'Zamknij komunikat powiadomień',
            icon: const Icon(Icons.close, size: 18),
            onPressed: onClose,
          ),
        ],
      ),
    );
  }
}
