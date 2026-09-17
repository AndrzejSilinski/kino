/*
 * Checkout: od koszyka do rozpoczętej płatności (Etap 8, blok H2).
 *
 * Checkout jest IDEMPOTENTNY po stronie serwera: powtórzony POST zwraca tę samą rezerwację i ten sam
 * client_secret. Dzięki temu store niczego nie zapisuje poza pamięcią — po F5 widok wywołuje start()
 * jeszcze raz (koszyk z pending_booking mówi, że płatność już trwa).
 *
 * Błędy rozgałęziamy po `code` (decyzja 20), a widok dostaje gotowy opis problemu:
 *   EMPTY_CART                   — blokady wygasły albo koszyk pusty: powrót do planu sali,
 *   BOOKING_ALREADY_PENDING      — do rozpoczętej płatności dobrano miejsca (np. w duplikacie karty),
 *   BOOKING_NOT_PAYABLE          — rezerwacja opłacona albo wygasła (context.booking_status),
 *   PAYMENT_PROVIDER_UNAVAILABLE — Stripe chwilowo niedostępny: można ponowić,
 *   SCREENING_NOT_BOOKABLE       — sprzedaż zamknięta.
 */
import { ref, shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { bookingsApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Booking, CheckoutResult } from '@/api/types';
import type { Deadline } from '@/composables/useCountdown';
import { messageFor } from '@/messages';

export type CheckoutPhase = 'idle' | 'starting' | 'ready' | 'abandoning' | 'expired';

export interface CheckoutProblem {
  code: string;
  message: string;
  /** Czy ponowienie tego samego żądania ma sens (sieć, operator płatności, błąd serwera). */
  retryable: boolean;
  /** Numer rezerwacji z kontekstu błędu (BOOKING_ALREADY_PENDING). */
  reference: string | null;
  /** Status rezerwacji z kontekstu błędu (BOOKING_NOT_PAYABLE). */
  bookingStatus: string | null;
}

const RETRYABLE = new Set(['PAYMENT_PROVIDER_UNAVAILABLE', 'NETWORK_ERROR', 'SERVER_ERROR', 'INVALID_RESPONSE', 'TOO_MANY_REQUESTS']);

export function problemFrom(error: unknown): CheckoutProblem {
  if (!isApiError(error)) {
    return { code: 'UNKNOWN', message: messageFor(error), retryable: true, reference: null, bookingStatus: null };
  }
  const text = (key: string) => (typeof error.context[key] === 'string' ? (error.context[key] as string) : null);
  return {
    code: error.code,
    message: messageFor(error),
    retryable: RETRYABLE.has(error.code),
    reference: text('booking_reference'),
    bookingStatus: text('booking_status'),
  };
}

export const useCheckoutStore = defineStore('checkout', () => {
  const screeningId = ref<number | null>(null);
  const result = shallowRef<CheckoutResult | null>(null);
  const deadline = shallowRef<Deadline | null>(null);
  const phase = ref<CheckoutPhase>('idle');
  const problem = shallowRef<CheckoutProblem | null>(null);
  let inFlight: Promise<boolean> | null = null;

  function reset(id: number | null = null): void {
    screeningId.value = id;
    result.value = null;
    deadline.value = null;
    phase.value = 'idle';
    problem.value = null;
  }

  /** POST checkout. Równoległe wywołania (podwójne kliknięcie, F5 + powrót karty) dzielą jedno żądanie. */
  function start(id: number): Promise<boolean> {
    if (screeningId.value !== id) {
      reset(id);
    }
    if (inFlight) {
      return inFlight;
    }
    phase.value = 'starting';
    problem.value = null;
    inFlight = (async () => {
      try {
        const { result: next } = await bookingsApi.checkout(id);
        result.value = next;
        deadline.value = { seconds: next.payment.expires_in_seconds, receivedAt: performance.now() };
        phase.value = 'ready';
        return true;
      } catch (error) {
        problem.value = problemFrom(error);
        phase.value = result.value ? 'ready' : 'idle';
        return false;
      } finally {
        inFlight = null;
      }
    })();
    return inFlight;
  }

  /**
   * Rezygnacja z płatności. Numer rezerwacji z wyniku checkoutu albo z koszyka (pending_booking) —
   * plan sali pozwala zrezygnować bez wchodzenia na ekran płatności.
   */
  async function abandon(reference: string | null = result.value?.booking.reference ?? null): Promise<Booking | null> {
    if (reference === null) {
      return null;
    }
    const previous = phase.value;
    phase.value = 'abandoning';
    problem.value = null;
    try {
      const booking = await bookingsApi.abandonPayment(reference);
      result.value = null;
      deadline.value = null;
      phase.value = 'idle';
      return booking;
    } catch (error) {
      problem.value = problemFrom(error);
      phase.value = previous;
      return null;
    }
  }

  /** Okno płatności minęło na liczniku. Miejsca zwolni serwer (scheduler), a my blokujemy płacenie. */
  function onPaymentExpired(): void {
    if (phase.value === 'ready') {
      phase.value = 'expired';
      deadline.value = null;
    }
  }

  return { screeningId, result, deadline, phase, problem, reset, start, abandon, onPaymentExpired };
});
