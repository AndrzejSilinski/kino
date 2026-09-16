/*
 * Plan sali i koszyk (API z Etapów 2, 3 i 6; DELETE zwraca koszyk od Etapu 8, blok F).
 *
 * Wszystkie trasy idą z nagłówkiem X-Session-Id (bookingSession: true) — to on mówi serwerowi,
 * czyje są blokady. Plan sali NIE ma limitu żądań (odświeżanie po utracie połączenia),
 * operacje na koszyku mają limit 30/min na sesję zakupową.
 */
import type { HttpClient } from './http';
import type { Cart, Envelope, SeatMapSnapshot } from './types';

export interface SeatsApi {
  seatMap(screeningId: number, signal?: AbortSignal): Promise<SeatMapSnapshot>;
  cart(screeningId: number): Promise<Cart>;
  lock(screeningId: number, seatId: number): Promise<Cart>;
  release(screeningId: number, seatId: number): Promise<Cart>;
  releaseAll(screeningId: number): Promise<Cart>;
}

export function createSeatsApi(http: HttpClient): SeatsApi {
  const base = (id: number) => `/screenings/${id}`;

  return {
    async seatMap(screeningId, signal) {
      return (await http.request<Envelope<SeatMapSnapshot>>(`${base(screeningId)}/seat-map`, { bookingSession: true, signal })).body.data;
    },
    async cart(screeningId) {
      return (await http.request<Envelope<Cart>>(`${base(screeningId)}/seat-locks`, { bookingSession: true })).body.data;
    },
    async lock(screeningId, seatId) {
      // Jedno miejsce na kliknięcie: odpowiedź (201 albo 409) dotyczy dokładnie tego fotela.
      return (await http.request<Envelope<Cart>>(`${base(screeningId)}/seat-locks`, {
        method: 'POST',
        body: { seat_ids: [seatId] },
        bookingSession: true,
      })).body.data;
    },
    async release(screeningId, seatId) {
      return (await http.request<Envelope<Cart>>(`${base(screeningId)}/seat-locks/${seatId}`, { method: 'DELETE', bookingSession: true })).body.data;
    },
    async releaseAll(screeningId) {
      return (await http.request<Envelope<Cart>>(`${base(screeningId)}/seat-locks`, { method: 'DELETE', bookingSession: true })).body.data;
    },
  };
}
