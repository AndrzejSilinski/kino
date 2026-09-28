// Arkusz płatności Stripe'a za JEDNĄ ścianą.
//
// To jedyny plik w aplikacji, który importuje `flutter_stripe`, i jest tak
// celowo (decyzja 315). `Stripe` to singleton z polami statycznymi i kanałami
// do kodu natywnego, więc w `flutter test` nie da się go wywołać — nie ma
// platformy. Gdyby stan płatności albo ekran wołały go wprost, ani jednego
// z nich nie dałoby się przetestować inaczej niż na telefonie. Za interfejsem
// `PaymentSheet` test podstawia atrapę, a aplikacja dostaje
// `StripePaymentSheet`.
//
// Sekret płatności wchodzi tu parametrem, nie jest nigdzie zapisywany ani
// logowany, a `toString()` wyniku pokazuje wyłącznie rozstrzygnięcie.
//
// Warunki natywne są spełnione od bloku C: `MainActivity` dziedziczy z
// `FlutterFragmentActivity`, a motyw z `Theme.AppCompat` — bez tego arkusz
// wysypuje się dopiero przy pierwszej próbie zapłaty na telefonie.

import 'package:flutter_stripe/flutter_stripe.dart';

/// Nazwa sprzedawcy pokazywana u góry arkusza.
///
/// Zostaje w aplikacji, bo to tylko napis na ekranie — nie wpływa na to, komu
/// i ile płaci klient. Wszystko, co wpływa (klucz, sekret, kwota), przychodzi
/// z serwera.
const String paymentMerchantName = 'Kino';

/// Czym skończył się arkusz.
///
/// `completed` znaczy „arkusz zamknął się powodzeniem", a NIE „zapłacono":
/// o zapłacie rozstrzyga serwer (decyzja 313). Nazwa jest taka właśnie po to,
/// żeby nikt tego nie pomylił, czytając kod wołający.
enum PaymentSheetResult { completed, cancelled, failed }

class PaymentSheetOutcome {
  const PaymentSheetOutcome(this.result, {this.message});

  final PaymentSheetResult result;

  /// Komunikat Stripe'a dla użytkownika, np. o odrzuconej karcie. Null przy
  /// powodzeniu i przy rezygnacji — rezygnacja nie jest błędem i nie ma o niej
  /// czego pisać.
  final String? message;

  bool get isCompleted => result == PaymentSheetResult.completed;

  bool get isCancelled => result == PaymentSheetResult.cancelled;

  @override
  String toString() => 'PaymentSheetOutcome(${result.name})';
}

/// Arkusz płatności widziany przez resztę aplikacji.
abstract interface class PaymentSheet {
  Future<PaymentSheetOutcome> pay({
    required String publishableKey,
    required String clientSecret,
    required String merchantName,
  });
}

class StripePaymentSheet implements PaymentSheet {
  const StripePaymentSheet();

  @override
  Future<PaymentSheetOutcome> pay({
    required String publishableKey,
    required String clientSecret,
    required String merchantName,
  }) async {
    try {
      // Klucz ustawiamy przy każdej płatności, bo pochodzi z odpowiedzi serwera
      // (decyzja 310), a nie z parametru buildu. Setter jest statyczny i tylko
      // zaznacza zmianę; ustawienia wchodzą leniwie przed pierwszym wywołaniem
      // — sprawdzone w źródle flutter_stripe 14.0.0.
      Stripe.publishableKey = publishableKey;
      await Stripe.instance.initPaymentSheet(
        paymentSheetParameters: SetupPaymentSheetParameters(
          paymentIntentClientSecret: clientSecret,
          merchantDisplayName: merchantName,
          // `allowsDelayedPaymentMethods` zostaje przy domyślnym false i to
          // jest decyzja, nie przeoczenie: metody z odroczonym rozliczeniem
          // (np. polecenie zapłaty) zamykają arkusz powodzeniem, a pieniądze
          // schodzą po dniach. Przy dziesięciominutowym oknie rezerwacji
          // miejsca byłyby zablokowane dla kogoś, kto nie zapłacił.
        ),
      );
      await Stripe.instance.presentPaymentSheet();
      return const PaymentSheetOutcome(PaymentSheetResult.completed);
    } on StripeException catch (error) {
      // Rezygnacja to nie awaria: klient zamknął arkusz i sam wie, że nie
      // zapłacił. Kod `Canceled` (jedno „l") sprawdzony w źródle
      // stripe_platform_interface 14.
      if (error.error.code == FailureCode.Canceled) {
        return const PaymentSheetOutcome(PaymentSheetResult.cancelled);
      }
      return PaymentSheetOutcome(
        PaymentSheetResult.failed,
        // Komunikat Stripe'a jest przetłumaczony i mówi o rzeczy, której my nie
        // wiemy (np. że bank odrzucił kartę). Własny tekst byłby tu gorszy.
        message: error.error.localizedMessage ?? error.error.message,
      );
    } on Exception catch (_) {
      // Wyjątku nie przekazujemy dalej: nie znamy jego treści, a mogłaby
      // zawierać dane płatności. Klient widzi jeden zrozumiały komunikat
      // składany wyżej.
      return const PaymentSheetOutcome(PaymentSheetResult.failed);
    }
  }
}
