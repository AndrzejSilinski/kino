// Jeden bilet: miejsce, cena, status i kod QR do pokazania przy wejściu.
//
// Kod QR pobieramy Z NAGŁÓWKIEM Authorization (decyzja 324) — adres obrazu bez
// tokenu oddaje 401, co sprawdziłem rozpoznaniem, a nie założyłem.
//
// Kod pokazujemy TYLKO dla biletu ważnego. Bilet wykorzystany ma adres kodu
// dalej, ale rysowanie go byłoby zaproszeniem do próby wejścia drugi raz —
// klient dostałby odmowę przy bramce bez żadnej swojej winy. W tym miejscu
// piszemy wprost, kiedy bilet został zeskanowany.

import 'package:cinema/core/cinema_time.dart';
import 'package:cinema/models/ticket.dart';
import 'package:flutter/material.dart';

/// Bok kwadratu z kodem QR. Jedna stała dla obrazu I dla jego zastępnika:
/// `Image.errorBuilder` NIE dostaje `width`/`height` obrazu — dostaje
/// ograniczenia rodzica, a tu rodzicem jest przewijana lista, czyli wysokość
/// nieograniczona (pułapka DX). Zastępnik musi więc wymierzyć się sam, tym
/// samym bokiem, żeby jedno nie odjechało od drugiego.
const double _qrSide = 220;

class TicketCard extends StatelessWidget {
  const TicketCard({required this.ticket, required this.headers, super.key});

  final Ticket ticket;

  /// Nagłówki do pobrania obrazu — liczone przy każdym wejściu na ekran.
  final Map<String, String> headers;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);
    final String? code = ticket.hasCode ? ticket.qrUrl : null;
    final TicketSeat? seat = ticket.seat;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Text(
                    seat == null ? 'Bilet' : 'Miejsce ${seat.label}',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                Text(
                  ticket.price.formatted,
                  style: theme.textTheme.titleMedium,
                ),
              ],
            ),
            const SizedBox(height: 4),
            // Etykieta statusu Z SERWERA: „Ważny”, „Wykorzystany”, „Anulowany”.
            Text(ticket.statusLabel, style: theme.textTheme.bodySmall),
            if (ticket.validatedAt case final CinemaTime wejscie) ...<Widget>[
              const SizedBox(height: 2),
              Text(
                'Zeskanowany ${wejscie.full}',
                style: theme.textTheme.bodySmall,
              ),
            ],
            if (code != null) ...<Widget>[
              const SizedBox(height: 16),
              Center(
                child: Image.network(
                  code,
                  headers: headers,
                  width: _qrSide,
                  height: _qrSide,
                  // Bez tego przy każdej przebudowie mrugałby pusty kwadrat.
                  gaplessPlayback: true,
                  // Obrazu może nie być: wygasły token, brak sieci, limit
                  // 30 żądań na minutę. Pusta ramka w takiej chwili jest
                  // najgorszą z możliwych odpowiedzi, bo klient stoi przy
                  // bramce i nie wie, czy to jego wina.
                  errorBuilder: (
                    BuildContext context,
                    Object error,
                    StackTrace? stack,
                  ) => const _CodeUnavailable(),
                  loadingBuilder:
                      (
                        BuildContext context,
                        Widget child,
                        ImageChunkEvent? progress,
                      ) => progress == null
                      ? child
                      : const SizedBox(
                          width: _qrSide,
                          height: _qrSide,
                          child: Center(child: CircularProgressIndicator()),
                        ),
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Pokaż ten kod przy wejściu na salę.',
                style: theme.textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Zastępnik obrazu kodu: ten sam kwadrat, komunikat zamiast kodu.
///
/// Wymiary MUSZĄ być tutaj, bo `errorBuilder` nie dziedziczy `width`/`height`
/// obrazu (pułapka DX). Tekst jest `Flexible` z `maxLines`, więc przy
/// powiększonej czcionce systemowej skróci się, zamiast wyjść za kwadrat.
class _CodeUnavailable extends StatelessWidget {
  const _CodeUnavailable();

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: _qrSide,
      height: _qrSide,
      color: colors.surfaceContainerHighest,
      padding: const EdgeInsets.all(12),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.qr_code_2_outlined, size: 32),
          const SizedBox(height: 8),
          Flexible(
            child: Text(
              'Nie udało się pobrać kodu. Odśwież rezerwację — '
              'bilet jest ważny niezależnie od tego.',
              style: Theme.of(context).textTheme.bodySmall,
              textAlign: TextAlign.center,
              maxLines: 6,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
