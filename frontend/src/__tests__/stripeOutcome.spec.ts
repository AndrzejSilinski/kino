import { describe, expect, it } from 'vitest';
import { outcomeOf } from '@/payments/stripe';

describe('wynik potwierdzenia w Stripe.js', () => {
  it.each(['requires_capture', 'succeeded', 'processing'])('status %s: o reszcie rozstrzyga webhook', (status) => {
    expect(outcomeOf({ paymentIntent: { status } })).toEqual({ kind: 'confirmed', status });
  });

  it('komunikat Stripe\'a tylko dla błędów przeznaczonych dla klienta (karta, walidacja)', () => {
    expect(outcomeOf({ error: { type: 'card_error', code: 'card_declined', message: 'Karta odrzucona.' } }))
      .toEqual({ kind: 'failed', message: 'Karta odrzucona.', code: 'card_declined' });
    const technical = outcomeOf({ error: { type: 'api_error', message: 'Internal detail: request req_123 failed' } });
    expect(technical.kind).toBe('failed');
    expect(technical.kind === 'failed' && technical.message).not.toContain('req_123');
  });

  it('płatność wróciła do wyboru metody (np. przerwane 3-D Secure) to porażka z ogólnym komunikatem', () => {
    expect(outcomeOf({ paymentIntent: { status: 'requires_payment_method' } })).toMatchObject({ kind: 'failed', code: 'requires_payment_method' });
  });
});
