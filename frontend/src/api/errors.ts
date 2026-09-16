/*
 * Błąd API w jednym kształcie (Etap 8, blok D).
 *
 * Serwer zawsze zwraca {message, code, context?, errors?} (decyzja 20). Front ROZGAŁĘZIA
 * SIĘ PO `code`, nigdy po treści komunikatu ani po samym statusie HTTP: komunikat może
 * zmienić redakcja tekstów, a jeden status (409, 422) niesie wiele różnych sytuacji.
 *
 * Dwa kody istnieją tylko po stronie klienta:
 *   NETWORK_ERROR    — żądanie nie dotarło do serwera albo nie wróciła odpowiedź,
 *   INVALID_RESPONSE — odpowiedź bez kształtu kontraktu (np. strona 502 z nginx).
 */
export type ApiErrorCode =
  | 'VALIDATION_FAILED'
  | 'UNAUTHENTICATED'
  | 'INVALID_CREDENTIALS'
  | 'FORBIDDEN'
  | 'RESOURCE_NOT_FOUND'
  | 'ENDPOINT_NOT_FOUND'
  | 'METHOD_NOT_ALLOWED'
  | 'TOO_MANY_REQUESTS'
  | 'SERVER_ERROR'
  | 'HTTP_ERROR'
  | 'INVALID_SESSION_ID'
  | 'SEATS_UNAVAILABLE'
  | 'SEAT_LOCK_LIMIT_EXCEEDED'
  | 'SCREENING_NOT_BOOKABLE'
  | 'PRICE_NOT_CONFIGURED'
  | 'EMPTY_SEAT_SELECTION'
  | 'DUPLICATE_SEATS'
  | 'SEATS_NOT_IN_HALL'
  | 'SEATS_INACTIVE'
  | 'EMPTY_CART'
  | 'BOOKING_ALREADY_PENDING'
  | 'BOOKING_NOT_PAYABLE'
  | 'BOOKING_TICKETS_UNAVAILABLE'
  | 'PAYMENT_PROVIDER_UNAVAILABLE'
  | 'PAYMENT_REJECTED'
  | 'CHANNEL_FORBIDDEN'
  | 'REALTIME_UNAVAILABLE'
  | 'NETWORK_ERROR'
  | 'INVALID_RESPONSE'
  // Kod spoza listy (nowy na serwerze) nadal jest poprawnym stringiem, ale podpowiedzi zostają.
  | (string & {});

export interface ApiErrorInit {
  status: number;
  code: ApiErrorCode;
  message: string;
  context?: Record<string, unknown>;
  errors?: Record<string, string[]>;
  retryAfterSeconds?: number | null;
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: ApiErrorCode;
  readonly context: Record<string, unknown>;
  readonly errors: Record<string, string[]>;
  readonly retryAfterSeconds: number | null;

  constructor(init: ApiErrorInit) {
    super(init.message);
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.context = init.context ?? {};
    this.errors = init.errors ?? {};
    this.retryAfterSeconds = init.retryAfterSeconds ?? null;
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}
