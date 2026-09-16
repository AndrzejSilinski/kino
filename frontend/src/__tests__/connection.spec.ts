import { describe, expect, it, vi } from 'vitest';
import { createAuthorizer, pusherOptions } from '@/realtime/connection';
import { ApiError } from '@/api/errors';
import type { HttpClient } from '@/api/http';

describe('połączenie WebSocket', () => {
  it('łączy się z hostem i portem strony, transport zgodny z protokołem (nginx przekazuje /app/ do Reverba)', () => {
    expect(pusherOptions({ protocol: 'http:', hostname: 'localhost', port: '8080' })).toMatchObject({ wsHost: 'localhost', wsPort: 8080, forceTLS: false, enabledTransports: ['ws'], cluster: 'reverb' });
    expect(pusherOptions({ protocol: 'https:', hostname: 'kino.example', port: '' })).toMatchObject({ wssPort: 443, forceTLS: true, enabledTransports: ['wss'] });
  });

  it('podpis kanału: POST /broadcasting/auth przez klienta HTTP z sesją zakupową, surowe {"auth"} do pusher-js', async () => {
    const request = vi.fn(async () => ({ status: 200, body: { auth: 'klucz:podpis' }, headers: new Headers() }));
    const http = { request, requestBlob: vi.fn() } as unknown as HttpClient;
    const callback = vi.fn();

    await createAuthorizer(http)({ socketId: '123.456', channelName: 'private-screenings.334' }, callback);

    expect(request).toHaveBeenCalledWith('/broadcasting/auth', { method: 'POST', body: { socket_id: '123.456', channel_name: 'private-screenings.334' }, bookingSession: true });
    expect(callback).toHaveBeenCalledWith(null, { auth: 'klucz:podpis' });
  });

  it('odmowa podpisu (403) trafia do pusher-js jako błąd ze statusem', async () => {
    const http = { request: vi.fn(async () => { throw new ApiError({ status: 403, code: 'CHANNEL_FORBIDDEN', message: 'Brak dostępu do tego kanału.' }); }), requestBlob: vi.fn() } as unknown as HttpClient;
    const callback = vi.fn();

    await createAuthorizer(http)({ socketId: '1.2', channelName: 'private-bookings.X' }, callback);

    expect(callback.mock.calls[0]?.[0]).toMatchObject({ status: 403 });
    expect(callback.mock.calls[0]?.[1]).toBeNull();
  });
});
