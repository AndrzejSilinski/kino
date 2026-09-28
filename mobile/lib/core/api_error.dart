// Jeden kształt błędu w całej aplikacji (koperta z decyzji 20).
//
// Serwer zwraca `message` po polsku (gotowe do wyświetlenia), `code` do
// rozgałęziania logiki, `context` z danymi pomocniczymi i `errors` przy 422.
// Aplikacja rozgałęzia się WYŁĄCZNIE po `code` — nigdy po treści komunikatu
// ani po samym statusie HTTP (konwencja z Etapu 8, decyzja 197).

/// Błąd API albo błąd samego klienta (brak sieci, zła odpowiedź).
class ApiError implements Exception {
  const ApiError({
    required this.code,
    required this.message,
    this.status,
    this.context = const <String, Object?>{},
    this.errors = const <String, List<String>>{},
    this.retryAfter,
  });

  /// Kody serwera używane przez aplikację (pełna lista w README).
  static const String unauthenticated = 'UNAUTHENTICATED';
  static const String invalidCredentials = 'INVALID_CREDENTIALS';
  static const String forbidden = 'FORBIDDEN';
  static const String resourceNotFound = 'RESOURCE_NOT_FOUND';
  static const String endpointNotFound = 'ENDPOINT_NOT_FOUND';
  static const String validationFailed = 'VALIDATION_FAILED';
  static const String tooManyRequests = 'TOO_MANY_REQUESTS';
  static const String serverError = 'SERVER_ERROR';

  /// Kody koszyka, potwierdzone rozpoznaniem na żywym serwerze.
  ///
  /// `SEATS_UNAVAILABLE` (409) niesie w `context.seat_ids` fotele zajęte przez
  /// kogoś innego. `SEATS_NOT_IN_HALL` (422, nie 404!) dostajemy zarówno dla
  /// nieistniejącego identyfikatora, jak i dla miejsca z innej sali — dla
  /// aplikacji znaczy to „mój plan sali jest nieaktualny”.
  /// `INVALID_SESSION_ID` (422) to zły format nagłówka `X-Session-Id`.
  static const String seatsUnavailable = 'SEATS_UNAVAILABLE';
  static const String seatsNotInHall = 'SEATS_NOT_IN_HALL';
  static const String invalidSessionId = 'INVALID_SESSION_ID';

  /// Kody checkoutu, potwierdzone rozpoznaniem fazy 3.
  ///
  /// `EMPTY_CART` (422) — nie ma czego kupić. `BOOKING_ALREADY_PENDING` (409)
  /// — koszyk zmienił się po rozpoczęciu płatności; w `context.booking_reference`
  /// przychodzi numer rezerwacji, do której klient ma wrócić.
  /// `BOOKING_NOT_PAYABLE` (409) — rezerwacja nie jest już do zapłacenia
  /// (wygasła albo zmieniła status).
  static const String emptyCart = 'EMPTY_CART';
  static const String bookingAlreadyPending = 'BOOKING_ALREADY_PENDING';
  static const String bookingNotPayable = 'BOOKING_NOT_PAYABLE';

  /// Kody, których serwer nie zna — powstają tylko po stronie aplikacji.
  static const String networkError = 'NETWORK_ERROR';
  static const String invalidResponse = 'INVALID_RESPONSE';

  final String code;
  final String message;
  final int? status;
  final Map<String, Object?> context;
  final Map<String, List<String>> errors;

  /// Z nagłówka `Retry-After` przy 429 — pokazujemy użytkownikowi, ile czekać.
  final Duration? retryAfter;

  /// Brak połączenia, timeout, DNS. Komunikat gotowy do wyświetlenia.
  factory ApiError.network([String? detail]) => ApiError(
    code: networkError,
    message: 'Brak połączenia z serwerem. Sprawdź sieć i spróbuj ponownie.',
    context: detail == null
        ? const <String, Object?>{}
        : <String, Object?>{'detail': detail},
  );

  /// Odpowiedź nie jest JSON-em albo nie ma pól, których wymaga kontrakt.
  factory ApiError.malformedResponse(String where) => ApiError(
    code: invalidResponse,
    message: 'Serwer zwrócił odpowiedź w nieoczekiwanym formacie.',
    context: <String, Object?>{'where': where},
  );

  /// Błąd z koperty serwera. Gdy koperta nie ma `code` i `message`, traktujemy
  /// odpowiedź jako niezgodną z kontraktem — nie wymyślamy komunikatu.
  factory ApiError.fromBody(int status, Object? body, {Duration? retryAfter}) {
    if (body is Map<String, Object?>) {
      final Object? code = body['code'];
      final Object? message = body['message'];
      final Object? context = body['context'];
      if (code is String && message is String) {
        return ApiError(
          code: code,
          message: message,
          status: status,
          context: context is Map<String, Object?>
              ? context
              : const <String, Object?>{},
          errors: parseErrors(body['errors']),
          retryAfter: retryAfter,
        );
      }
    }
    return ApiError(
      code: invalidResponse,
      message: 'Serwer zwrócił odpowiedź w nieoczekiwanym formacie.',
      status: status,
      retryAfter: retryAfter,
    );
  }

  /// `errors` z 422: mapa pole -> lista komunikatów.
  static Map<String, List<String>> parseErrors(Object? raw) {
    if (raw is! Map<String, Object?>) {
      return const <String, List<String>>{};
    }
    final Map<String, List<String>> out = <String, List<String>>{};
    raw.forEach((String key, Object? value) {
      if (value is List) {
        out[key] = value.whereType<String>().toList(growable: false);
      }
    });
    return out;
  }

  /// Identyfikatory miejsc z `context.seat_ids` — przy 409 to fotele zajęte
  /// przez kogoś innego, przy `SEATS_NOT_IN_HALL` te, których serwer nie zna
  /// w tej sali. Pusta lista, gdy pola nie ma.
  List<int> get seatIds {
    final Object? raw = context['seat_ids'];
    if (raw is! List) {
      return const <int>[];
    }
    return raw.whereType<int>().toList(growable: false);
  }

  /// Numer rezerwacji z `context.booking_reference` — przy 409 checkoutu
  /// wskazuje rozpoczętą płatność, do której trzeba wrócić.
  String? get bookingReference {
    final Object? raw = context['booking_reference'];
    return raw is String && raw.isNotEmpty ? raw : null;
  }

  /// Pierwszy komunikat walidacji dla pola formularza albo null.
  String? fieldError(String field) {
    final List<String>? messages = errors[field];
    return (messages == null || messages.isEmpty) ? null : messages.first;
  }

  /// Bez treści komunikatu i bez `context`: w logach nie ma danych z serwera.
  @override
  String toString() => 'ApiError(code: $code, status: $status)';
}
