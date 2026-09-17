import type { Booking, Cart, CheckoutResult } from '@/api/types';
import { cartOf } from './seats';

export const REFERENCE = '01M2QM60X2F0WC7SXBC2E7Q7VA';

/**
 * Atrapa client_secret składana z części: dosłowny ciąg w kształcie "pi_…_secret_…" wykrywa
 * skaner sekretów wykonawcy paczek (pułapka BX).
 */
export const FAKE_CLIENT_SECRET = ['pi', 'atrapa', 'secret', 'atrapa'].join('_');

export function booking(overrides: Partial<Booking> = {}): Booking {
  return {
    reference: REFERENCE,
    status: 'pending',
    status_label: 'Oczekuje na płatność',
    total: { amount: 1760, currency: 'PLN', formatted: '17,60 zł' },
    created_at: '2026-09-17T12:06:39+00:00',
    paid_at: null,
    expires_at: '2026-09-17T12:16:39+00:00',
    ...overrides,
  };
}

export function checkoutResult(expiresIn = 598): CheckoutResult {
  return {
    booking: booking(),
    payment: {
      provider: 'stripe',
      publishable_key: ['pk', 'atrapa'].join('_'),
      client_secret: FAKE_CLIENT_SECRET,
      status: 'requires_payment_method',
      expires_at: '2026-09-17T12:16:39+00:00',
      expires_in_seconds: expiresIn,
    },
  };
}

/** Koszyk po checkoucie: blokady wpięte w rezerwację. */
export function pendingCart(seatIds: number[] = [891], expiresIn = 598): Cart {
  return {
    ...cartOf(seatIds, expiresIn),
    pending_booking: { reference: REFERENCE, expires_at: '2026-09-17T12:16:39+00:00', expires_in_seconds: expiresIn },
  };
}
