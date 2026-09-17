/*
 * Rezerwacje i płatność (Etap 8, blok H2): checkout i rezygnacja z płatności.
 *
 * Checkout wymaga DWÓCH rzeczy naraz: tokenu (rezerwacja ma właściciela) i nagłówka X-Session-Id
 * (koszyk należy do sesji zakupowej karty). Ciało żądania jest puste — miejsca i kwotę serwer bierze
 * z blokad i cennika, klient nie ma tu nic do powiedzenia.
 */
import type { HttpClient } from './http';
import type { Booking, CheckoutResult, Envelope } from './types';

export interface CheckoutResponse {
  /** true = 201 (płatność utworzona teraz), false = 200 (ta sama, rozpoczęta wcześniej). */
  created: boolean;
  result: CheckoutResult;
}

export interface BookingsApi {
  checkout(screeningId: number): Promise<CheckoutResponse>;
  /** Szczegóły rezerwacji właściciela (seans, bilety). 403/404 dla cudzej albo nieistniejącej. */
  show(reference: string, signal?: AbortSignal): Promise<Booking>;
  abandonPayment(reference: string): Promise<Booking>;
}

export function createBookingsApi(http: HttpClient): BookingsApi {
  return {
    async checkout(screeningId) {
      const { status, body } = await http.request<Envelope<CheckoutResult>>(`/screenings/${screeningId}/booking`, {
        method: 'POST',
        bookingSession: true,
      });
      return { created: status === 201, result: body.data };
    },
    async show(reference, signal) {
      return (await http.request<Envelope<Booking>>(`/bookings/${encodeURIComponent(reference)}`, { signal })).body.data;
    },
    async abandonPayment(reference) {
      return (await http.request<Envelope<Booking>>(`/bookings/${encodeURIComponent(reference)}/payment`, { method: 'DELETE' })).body.data;
    },
  };
}
