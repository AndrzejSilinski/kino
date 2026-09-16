/*
 * Identyfikator sesji zakupowej X-Session-Id po stronie przeglądarki (Etap 8, blok D).
 *
 * Identyfikator WYDAJE SERWER (decyzja 15) i odsyła go w nagłówku każdej odpowiedzi tras
 * koszyka. Klient tylko go zapamiętuje i odsyła.
 *
 * sessionStorage, a nie localStorage: każda karta ma własny koszyk. F5 zachowuje blokady,
 * a dwie karty zachowują się jak dwóch klientów — nie ma dwóch liczników walczących
 * o jeden koszyk. Gdy magazyn jest niedostępny, identyfikator żyje w pamięci karty.
 */
import { readItem, removeItem, safeStorage, writeItem } from '@/lib/storage';

export const BOOKING_SESSION_HEADER = 'X-Session-Id';
export const BOOKING_SESSION_KEY = 'cinema.booking.session';
/** Ten sam format co ResolveBookingSession::PATTERN na serwerze. */
export const BOOKING_SESSION_PATTERN = /^[A-Za-z0-9]{32}$/;

export interface BookingSessionStore {
  get(): string | null;
  set(id: string): void;
  clear(): void;
}

export function createBookingSessionStore(storage: Storage | null = safeStorage('session')): BookingSessionStore {
  let memory: string | null = null;

  return {
    get() {
      const stored = readItem(storage, BOOKING_SESSION_KEY) ?? memory;
      return stored !== null && BOOKING_SESSION_PATTERN.test(stored) ? stored : null;
    },
    set(id: string) {
      // Nagłówek w złym formacie oznacza błąd po drodze (proxy, podmiana) — nie zapamiętujemy go.
      if (!BOOKING_SESSION_PATTERN.test(id)) {
        return;
      }
      memory = id;
      writeItem(storage, BOOKING_SESSION_KEY, id);
    },
    clear() {
      memory = null;
      removeItem(storage, BOOKING_SESSION_KEY);
    },
  };
}
