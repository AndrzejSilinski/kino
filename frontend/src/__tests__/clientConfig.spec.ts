import { describe, expect, it, vi } from 'vitest';
import { fetchClientConfig } from '@/api/clientConfig';

const jsonResponse = (status: number, body: unknown): Response =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('fetchClientConfig', () => {
  it('pobiera konfigurację z tego samego originu i zwraca zawartość koperty data', async () => {
    const data = {
      api_version: 'v1',
      realtime: { broadcaster: 'reverb', key: 'publiczny-klucz', path: '/app' },
      booking: { seat_lock_ttl_seconds: 600, max_seats_per_session: 10, payment_window_seconds: 600 },
      push: { enabled: false },
    };
    const fetchMock = vi.fn(async () => jsonResponse(200, { data }));

    await expect(fetchClientConfig(fetchMock)).resolves.toEqual(data);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/client-config', { headers: { Accept: 'application/json' } });
  });

  it('zgłasza błąd przy odpowiedzi innej niż 2xx', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(503, { message: 'x', code: 'SERVER_ERROR' }));

    await expect(fetchClientConfig(fetchMock)).rejects.toThrow('HTTP 503');
  });

  it('odrzuca odpowiedź bez klucza Reverba', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, { data: { realtime: {} } }));

    await expect(fetchClientConfig(fetchMock)).rejects.toThrow('Nieprawidłowa odpowiedź');
  });
});
