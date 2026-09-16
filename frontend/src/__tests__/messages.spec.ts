import { describe, expect, it } from 'vitest';
import { ApiError } from '@/api/errors';
import { messageFor } from '@/messages';

describe('komunikaty błędów', () => {
  it('limit żądań podaje liczbę sekund, brak sieci ma własny tekst', () => {
    expect(messageFor(new ApiError({ status: 429, code: 'TOO_MANY_REQUESTS', message: 'x', retryAfterSeconds: 42 }))).toBe('Zbyt wiele prób. Spróbuj ponownie za 42 s.');
    expect(messageFor(new ApiError({ status: 0, code: 'NETWORK_ERROR', message: '' }))).toContain('Brak połączenia');
  });

  it('pozostałe kody pokazują komunikat serwera, a nie-API błąd tekst ogólny', () => {
    expect(messageFor(new ApiError({ status: 409, code: 'SEATS_UNAVAILABLE', message: 'Miejsca B7 zostały właśnie zajęte przez kogoś innego.' }))).toBe('Miejsca B7 zostały właśnie zajęte przez kogoś innego.');
    expect(messageFor(new Error('boom'))).toBe('Wystąpił nieoczekiwany błąd. Spróbuj ponownie.');
  });
});
