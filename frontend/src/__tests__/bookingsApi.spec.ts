import { describe, expect, it, vi } from 'vitest';
import { createBookingSessionStore } from '@/api/bookingSession';
import { createBookingsApi } from '@/api/bookings';
import { createHttpClient } from '@/api/http';
import { booking, checkoutResult, REFERENCE } from './fixtures/checkout';

const SESSION = 'S'.repeat(32);

function setup() {
  const fetchMock = vi.fn<typeof fetch>();
  const sessions = createBookingSessionStore(null);
  sessions.set(SESSION);
  const http = createHttpClient({ fetchImpl: fetchMock, getToken: () => 'token-testowy', bookingSession: sessions, onUnauthorized: vi.fn() });
  const sent = () => {
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    return { url: String(url), method: (init as RequestInit).method, headers: new Headers((init as RequestInit).headers), body: (init as RequestInit).body };
  };
  return { api: createBookingsApi(http), fetchMock, sent };
}

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('API rezerwacji', () => {
  it('checkout: POST bez ciała, z tokenem I nagłówkiem sesji zakupowej; 201 = nowa płatność', async () => {
    const { api, fetchMock, sent } = setup();
    fetchMock.mockResolvedValue(json(201, { data: checkoutResult() }));

    const response = await api.checkout(334);

    expect(sent().url).toBe('/api/v1/screenings/334/booking');
    expect(sent().method).toBe('POST');
    expect(sent().body).toBeUndefined();
    expect(sent().headers.get('Authorization')).toBe('Bearer token-testowy');
    expect(sent().headers.get('X-Session-Id')).toBe(SESSION);
    expect(response.created).toBe(true);
    expect(response.result.booking.reference).toBe(REFERENCE);
  });

  it('checkout powtórzony (200) to ta sama, wcześniej rozpoczęta płatność', async () => {
    const { api, fetchMock } = setup();
    fetchMock.mockResolvedValue(json(200, { data: checkoutResult() }));

    expect((await api.checkout(334)).created).toBe(false);
  });

  it('szczegóły rezerwacji: GET z tokenem, bez nagłówka sesji zakupowej', async () => {
    const { api, fetchMock, sent } = setup();
    fetchMock.mockResolvedValue(json(200, { data: booking({ status: 'paid', status_label: 'Opłacona' }) }));

    expect((await api.show(REFERENCE)).status).toBe('paid');
    expect(sent().url).toBe(`/api/v1/bookings/${REFERENCE}`);
    expect(sent().headers.get('Authorization')).toBe('Bearer token-testowy');
    expect(sent().headers.get('X-Session-Id')).toBeNull();
  });

  it('rezygnacja: DELETE na podzasobie payment, bez nagłówka sesji zakupowej', async () => {
    const { api, fetchMock, sent } = setup();
    fetchMock.mockResolvedValue(json(200, { data: booking({ status: 'cancelled', status_label: 'Anulowana' }) }));

    const result = await api.abandonPayment(REFERENCE);

    expect(sent().url).toBe(`/api/v1/bookings/${REFERENCE}/payment`);
    expect(sent().method).toBe('DELETE');
    expect(sent().headers.get('X-Session-Id')).toBeNull();
    expect(result.status).toBe('cancelled');
  });
});
