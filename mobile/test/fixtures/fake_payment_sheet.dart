// Atrapa arkusza płatności.
//
// Cała wartość decyzji 315 mieści się w tym pliku: dzięki interfejsowi
// `PaymentSheet` testy stanu i ekranu przechodzą ścieżkę zakupu do końca, bez
// platformy natywnej, bez sieci i bez ani jednego prawdziwego grosza.

import 'package:cinema/core/payment_sheet.dart';

class FakePaymentSheet implements PaymentSheet {
  FakePaymentSheet({
    this.outcome = const PaymentSheetOutcome(PaymentSheetResult.completed),
  });

  /// Czym ma się skończyć arkusz w tym teście.
  final PaymentSheetOutcome outcome;

  int calls = 0;

  /// Co arkusz DOSTAŁ — test sprawdza, że klucz i sekret pochodzą z serwera.
  String? publishableKey;
  String? clientSecret;
  String? merchantName;

  @override
  Future<PaymentSheetOutcome> pay({
    required String publishableKey,
    required String clientSecret,
    required String merchantName,
  }) async {
    calls++;
    this.publishableKey = publishableKey;
    this.clientSecret = clientSecret;
    this.merchantName = merchantName;
    return outcome;
  }
}
