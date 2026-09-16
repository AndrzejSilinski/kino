/*
 * Teksty błędów dla klienta (Etap 8, blok D). Jeden język, więc bez vue-i18n: serwer już
 * zwraca komunikaty po polsku, a tu są tylko teksty kodów, których serwer nie formułuje
 * (brak sieci, limit żądań z liczbą sekund). Jeden moduł = jedno miejsce do podmiany.
 */
import { isApiError } from '@/api/errors';

const FALLBACK = 'Wystąpił nieoczekiwany błąd. Spróbuj ponownie.';

export function messageFor(error: unknown): string {
  if (!isApiError(error)) {
    return FALLBACK;
  }

  switch (error.code) {
    case 'NETWORK_ERROR':
      return 'Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.';
    case 'TOO_MANY_REQUESTS':
      return error.retryAfterSeconds !== null
        ? `Zbyt wiele prób. Spróbuj ponownie za ${error.retryAfterSeconds} s.`
        : 'Zbyt wiele prób. Spróbuj ponownie za chwilę.';
    default:
      return error.message || FALLBACK;
  }
}

/** Pierwszy komunikat walidacji dla pola (422 VALIDATION_FAILED). */
export function fieldError(errors: Record<string, string[]>, field: string): string | undefined {
  return errors[field]?.[0];
}
