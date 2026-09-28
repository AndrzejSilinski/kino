// Kwoty formatuje serwer — aplikacja nigdy nie składa napisu z groszy.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/core/money.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('czyta kwotę, walutę i gotowy napis', () {
    final Money money = Money.fromJson(<String, Object?>{
      'amount': 3500,
      'currency': 'PLN',
      'formatted': '35,00 zł',
    }, 'ticket.price');

    expect(money.amount, 3500);
    expect(money.currency, 'PLN');
    expect(money.toString(), '35,00 zł');
  });

  test('brak pola to INVALID_RESPONSE ze wskazaniem miejsca', () {
    try {
      Money.fromJson(<String, Object?>{'amount': 3500}, 'ticket.price');
      fail('oczekiwano ApiError');
    } on ApiError catch (error) {
      expect(error.code, ApiError.invalidResponse);
      expect(error.context['where'], 'ticket.price.currency');
    }
  });
}
