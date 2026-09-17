/*
 * Czekanie na wynik płatności (Etap 8, blok H3) — czysty moduł bez Vue i bez sieci.
 *
 * O opłaceniu rozstrzyga WEBHOOK Stripe'a na serwerze, nie przeglądarka: potwierdzenie w Stripe.js
 * mówi tylko, że klient skończył swoją część. Ekran wyniku czeka więc na zmianę statusu rezerwacji.
 *
 * Dwa źródła naraz:
 *   1. kanał private-bookings.{reference} (zdarzenie booking.status-changed, Etap 6) — natychmiast,
 *      a po pusher:subscription_succeeded jeszcze jeden odczyt (zmiana z okna przed subskrypcją),
 *   2. odczyt GET /bookings/{reference} z rosnącymi odstępami i LIMITEM prób.
 * Dlaczego oba, skoro plan sali odpytywania nie ma (decyzja 210): zdarzenie może nie zostać wysłane
 * (bezpiecznik RealtimeNotifier przy niedziałającym Reverbie), a tu chodzi o jeden zasób jednego
 * klienta przez kilka minut, nie o setki klientów planu sali. Po wyczerpaniu prób: status "delayed"
 * i komunikat — subskrypcja zostaje, więc późne zdarzenie nadal zamknie oczekiwanie.
 *
 * Zdarzenie nie jest źródłem prawdy o rezerwacji: po nim i tak czytamy rezerwację z API.
 */
import type { Booking } from '@/api/types';
import type { RealtimeConnection } from './connection';

export type BookingWatchStatus = 'waiting' | 'done' | 'delayed';

export const DEFAULT_POLL_DELAYS = [2000, 3000, 5000, 8000, 13000, 20000, 30000, 30000, 30000, 30000];

export interface BookingWatchOptions {
  reference: string;
  /** null, gdy WebSocket jest niedostępny — zostaje samo odpytywanie. */
  connection: RealtimeConnection | null;
  fetchBooking(): Promise<Booking>;
  onBooking(booking: Booking): void;
  onStatus?(status: BookingWatchStatus): void;
  onError?(error: unknown): void;
  pollDelays?: number[];
  setTimer?(callback: () => void, ms: number): unknown;
  clearTimer?(handle: unknown): void;
}

export interface BookingWatch {
  start(): Promise<void>;
  stop(): void;
}

export function watchBooking(options: BookingWatchOptions): BookingWatch {
  const delays = options.pollDelays ?? DEFAULT_POLL_DELAYS;
  const setTimer = options.setTimer ?? ((callback, ms) => setTimeout(callback, ms));
  const clearTimer = options.clearTimer ?? ((handle) => clearTimeout(handle as ReturnType<typeof setTimeout>));
  let unsubscribe: (() => void) | null = null;
  let timer: unknown = null;
  let attempt = 0;
  let stopped = false;
  let running: Promise<void> | null = null;
  let again = false;

  function finish(status: BookingWatchStatus): void {
    if (timer !== null) {
      clearTimer(timer);
      timer = null;
    }
    if (status === 'done') {
      stopped = true;
      unsubscribe?.();
      unsubscribe = null;
    }
    options.onStatus?.(status);
  }

  /** Jeden odczyt naraz; wezwanie w trakcie odczytu powtarza go po zakończeniu (świeży stan). */
  function refresh(): Promise<void> {
    if (stopped) {
      return Promise.resolve();
    }
    if (running) {
      again = true;
      return running;
    }
    running = (async () => {
      do {
        again = false;
        try {
          const booking = await options.fetchBooking();
          if (stopped) {
            return;
          }
          options.onBooking(booking);
          if (booking.status !== 'pending') {
            finish('done');
            return;
          }
        } catch (error) {
          options.onError?.(error);
        }
      } while (again && !stopped);
    })().finally(() => {
      running = null;
    });
    return running;
  }

  function schedulePoll(): void {
    if (stopped) {
      return;
    }
    const delay = delays[attempt];
    if (delay === undefined) {
      finish('delayed');
      return;
    }
    timer = setTimer(() => {
      timer = null;
      attempt++;
      void refresh().then(schedulePoll);
    }, delay);
  }

  return {
    async start() {
      options.onStatus?.('waiting');
      if (options.connection) {
        unsubscribe = options.connection.subscribe(`private-bookings.${options.reference}`, {
          onSubscribed: () => void refresh(),
          // 403 (nie właściciel) albo 503: zostaje odpytywanie, ekran i tak pokaże stan z API.
          onError: () => {},
          events: { 'booking.status-changed': () => void refresh() },
        });
      }
      await refresh();
      schedulePoll();
    },
    stop() {
      stopped = true;
      if (timer !== null) {
        clearTimer(timer);
        timer = null;
      }
      unsubscribe?.();
      unsubscribe = null;
    },
  };
}
