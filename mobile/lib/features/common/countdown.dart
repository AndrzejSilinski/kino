// Odliczanie do wygaśnięcia blokady miejsc (a w bloku H — okna płatności).
//
// Liczymy od LICZBY SEKUND podanej przez serwer, nie od różnicy dat na
// telefonie (decyzja 288): zegar urządzenia bywa przestawiony, a czasy blokad
// przychodzą w UTC, gdy godziny seansu są w strefie kina (pułapka CY).
//
// Gdy serwer przyśle nową wartość (po każdej operacji na koszyku), odliczanie
// startuje od niej — inaczej pokazywalibyśmy czas pierwszej blokady do końca
// wizyty na ekranie.

import 'dart:async';

import 'package:flutter/material.dart';

class Countdown extends StatefulWidget {
  const Countdown({
    required this.seconds,
    this.onExpired,
    this.style,
    super.key,
  });

  /// Sekundy z serwera (`expires_in_seconds`).
  final int seconds;

  /// Wołane raz, w momencie dojścia do zera.
  final VoidCallback? onExpired;

  final TextStyle? style;

  @override
  State<Countdown> createState() => _CountdownState();
}

class _CountdownState extends State<Countdown> {
  Timer? _timer;
  late int _left;

  @override
  void initState() {
    super.initState();
    _restart();
  }

  @override
  void didUpdateWidget(Countdown oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.seconds != widget.seconds) {
      _restart();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _restart() {
    _timer?.cancel();
    _left = widget.seconds < 0 ? 0 : widget.seconds;
    if (_left == 0) {
      // Zero sekund pokazujemy, ale NIE zgłaszamy wygaśnięcia. Wywołanie
      // `onExpired` z `initState` wypadłoby w trakcie budowania drzewa,
      // a ono unieważnia provider — Riverpod słusznie by na to nakrzyczał.
      // Zero z serwera oznacza i tak blokadę, której już nie ma: najbliższa
      // operacja na koszyku dostanie 409 i ekran się odświeży.
      return;
    }
    _timer = Timer.periodic(const Duration(seconds: 1), (Timer timer) {
      if (!mounted) {
        return;
      }
      setState(() => _left = _left > 0 ? _left - 1 : 0);
      if (_left == 0) {
        timer.cancel();
        widget.onExpired?.call();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final int minutes = _left ~/ 60;
    final int seconds = _left % 60;
    return Text(
      '$minutes:${seconds.toString().padLeft(2, '0')}',
      style: widget.style,
    );
  }
}
