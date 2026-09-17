import { describe, expect, it, vi } from 'vitest';
// ?raw: Vite podaje treść pliku jako tekst — bez modułów Node w testach przeglądarkowych.
import workerSource from '../../public/firebase-messaging-sw.js?raw';

/**
 * public/firebase-messaging-sw.js to zwykły skrypt workera (bez modułów i bez Firebase).
 * Uruchamiamy go z atrapą "self" i sami wywołujemy zdarzenia push i notificationclick.
 */
function loadWorker(windows: { url: string; focus: () => Promise<unknown> }[] = []) {
  const listeners = new Map<string, (event: unknown) => void>();
  const self = {
    location: { origin: 'http://localhost:8080' },
    addEventListener: (type: string, listener: (event: unknown) => void) => listeners.set(type, listener),
    skipWaiting: vi.fn(),
    registration: { showNotification: vi.fn(async () => {}) },
    clients: { claim: vi.fn(), matchAll: vi.fn(async () => windows), openWindow: vi.fn(async () => null) },
  };
  new Function('self', workerSource)(self);

  async function dispatch(type: string, event: Record<string, unknown>) {
    let pending: Promise<unknown> = Promise.resolve();
    listeners.get(type)!({ ...event, waitUntil: (promise: Promise<unknown>) => { pending = promise; } });
    await pending;
  }
  return { self, dispatch };
}

const pushEvent = (payload: unknown) => ({ data: { json: () => payload } });

describe('service worker powiadomień', () => {
  it('pokazuje powiadomienie z FCM z adresem do otwarcia i tagiem (kolejne o tej samej rezerwacji zastępuje poprzednie)', async () => {
    const { self, dispatch } = loadWorker();

    await dispatch('push', pushEvent({ notification: { title: 'Płatność przyjęta', body: 'Barbie · 20.09, godz. 18:30' }, data: { type: 'booking.paid', url: '/bookings/01M2QPPNMXN47ZN3WAQ7WG23VA' } }));

    expect(self.registration.showNotification).toHaveBeenCalledWith('Płatność przyjęta', {
      body: 'Barbie · 20.09, godz. 18:30',
      tag: 'booking.paid:/bookings/01M2QPPNMXN47ZN3WAQ7WG23VA',
      data: { url: '/bookings/01M2QPPNMXN47ZN3WAQ7WG23VA' },
      lang: 'pl',
    });
  });

  it.each(['https://evil.example/phish', '//evil.example', '/\\evil.example', 42])('adres spoza aplikacji (%s) zamieniony na /account', async (url) => {
    const { self, dispatch } = loadWorker();

    await dispatch('push', pushEvent({ notification: { title: 'x' }, data: { url } }));

    expect(self.registration.showNotification).toHaveBeenCalledWith('x', expect.objectContaining({ data: { url: '/account' } }));
  });

  it('zawsze pokazuje powiadomienie, także dla pustej albo uszkodzonej wiadomości (wymóg userVisibleOnly)', async () => {
    const { self, dispatch } = loadWorker();

    await dispatch('push', { data: { json: () => { throw new SyntaxError('nie JSON'); } } });

    expect(self.registration.showNotification).toHaveBeenCalledWith('Kino', expect.objectContaining({ data: { url: '/account' } }));
  });

  it('kliknięcie: otwarta karta z tym adresem na wierzch, inaczej nowa karta', async () => {
    const focus = vi.fn(async () => null);
    const opened = loadWorker([{ url: 'http://localhost:8080/bookings/X', focus }]);
    const notification = (url: string) => ({ notification: { close: vi.fn(), data: { url } } });

    await opened.dispatch('notificationclick', notification('/bookings/X'));
    expect(focus).toHaveBeenCalled();
    expect(opened.self.clients.openWindow).not.toHaveBeenCalled();

    await opened.dispatch('notificationclick', notification('/bookings/Y'));
    expect(opened.self.clients.openWindow).toHaveBeenCalledWith('http://localhost:8080/bookings/Y');
  });
});
