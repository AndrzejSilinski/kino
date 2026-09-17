/*
 * Rezerwacje i płatność (Etap 8, blok H2): checkout i rezygnacja z płatności.
 *
 * Checkout wymaga DWÓCH rzeczy naraz: tokenu (rezerwacja ma właściciela) i nagłówka X-Session-Id
 * (koszyk należy do sesji zakupowej karty). Ciało żądania jest puste — miejsca i kwotę serwer bierze
 * z blokad i cennika, klient nie ma tu nic do powiedzenia.
 */
import { ApiError } from './errors';
import type { BlobResponse, HttpClient } from './http';
import { apiPathFromUrl } from '@/lib/apiPath';
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
  /** Obraz kodu QR biletu (PNG) z qr_url; tylko adres z naszego originu — tam wysyłamy token. */
  ticketQr(qrUrl: string, signal?: AbortSignal): Promise<Blob>;
  /** PDF ze wszystkimi biletami rezerwacji; nazwa pliku z Content-Disposition. */
  ticketsPdf(reference: string): Promise<BlobResponse>;
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
    async ticketQr(qrUrl, signal) {
      const path = apiPathFromUrl(qrUrl);
      if (path === null) {
        throw new ApiError({ status: 0, code: 'INVALID_RESPONSE', message: 'Nieprawidłowy adres kodu QR.' });
      }
      return (await http.requestBlob(path, { signal })).blob;
    },
    async ticketsPdf(reference) {
      return http.requestBlob(`/bookings/${encodeURIComponent(reference)}/tickets/pdf`);
    },
    async abandonPayment(reference) {
      return (await http.request<Envelope<Booking>>(`/bookings/${encodeURIComponent(reference)}/payment`, { method: 'DELETE' })).body.data;
    },
  };
}
