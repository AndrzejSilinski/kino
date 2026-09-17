/*
 * Wspólne instancje warstwy API dla całej aplikacji (Etap 8, blok D).
 *
 * Klient HTTP nie importuje store'ów ani routera (brak cyklicznych zależności i łatwe testy):
 * main.ts wpina mu przez configureHttp() sposób odczytu tokenu i reakcję na 401.
 */
import { createAuthApi } from './auth';
import { createBookingsApi } from './bookings';
import { createBookingSessionStore } from './bookingSession';
import { createCatalogApi } from './catalog';
import { createHttpClient } from './http';
import { createSeatsApi } from './seats';

let readToken: () => string | null = () => null;
let handleUnauthorized: () => void = () => {};

export function configureHttp(hooks: { getToken: () => string | null; onUnauthorized: () => void }): void {
  readToken = hooks.getToken;
  handleUnauthorized = hooks.onUnauthorized;
}

export const bookingSession = createBookingSessionStore();

export const http = createHttpClient({
  getToken: () => readToken(),
  bookingSession,
  onUnauthorized: () => handleUnauthorized(),
});

export const authApi = createAuthApi(http);

export const catalogApi = createCatalogApi(http);

export const seatsApi = createSeatsApi(http);

export const bookingsApi = createBookingsApi(http);
