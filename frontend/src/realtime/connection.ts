/*
 * Połączenie WebSocket z Reverbem (Etap 8, blok G) — cienka warstwa nad pusher-js.
 *
 * DLACZEGO pusher-js BEZ laravel-echo: Echo to nakładka na pusher-js (listen('.seats.changed')),
 * a my potrzebujemy właśnie niskopoziomowych zdarzeń: pusher:subscription_succeeded (ponowny odczyt
 * wersji po subskrypcji, decyzja 125), pusher:subscription_error (403/503) i zmian stanu połączenia.
 * Panel ma Echo, bo Livewire wymaga window.Echo; SPA tego wymogu nie ma. Ta sama biblioteka
 * i wersja (8.6.0) co sonda WebSocket z Etapu 6.
 *
 * Reszta aplikacji zna tylko interfejs RealtimeConnection — testy podstawiają atrapę bez sieci.
 */
import Pusher from 'pusher-js';
import type { HttpClient } from '@/api/http';
import { isApiError } from '@/api/errors';

export type ConnectionState = 'initialized' | 'connecting' | 'connected' | 'unavailable' | 'failed' | 'disconnected';

export interface SubscriptionHandlers {
  onSubscribed(): void;
  onError(status: number | null): void;
  events: Record<string, (data: unknown) => void>;
}

export interface RealtimeConnection {
  readonly state: ConnectionState;
  onStateChange(listener: (state: ConnectionState) => void): () => void;
  subscribe(channelName: string, handlers: SubscriptionHandlers): () => void;
}

export interface PusherConnectionOptions {
  key: string;
  http: HttpClient;
  location?: Pick<Location, 'protocol' | 'hostname' | 'port'>;
}

/** Opcje klienta: host i port strony (nginx przekazuje /app/ do Reverba), transport zgodny z protokołem. */
export function pusherOptions(location: Pick<Location, 'protocol' | 'hostname' | 'port'>) {
  const secure = location.protocol === 'https:';
  const port = Number(location.port) || (secure ? 443 : 80);
  return {
    // cluster jest wymagany przez typy pusher-js 8, ale przy własnym wsHost nie ma znaczenia (pułapka AU).
    cluster: 'reverb',
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    // Tylko transport zgodny ze stroną: na http pusher-js próbowałby też wss i zapisywał błędy TLS.
    enabledTransports: [secure ? 'wss' : 'ws'] as ('ws' | 'wss')[],
  };
}

/**
 * Podpis subskrypcji przez nasz klient HTTP: token bearer, jeśli jest (kanał rezerwacji),
 * i X-Session-Id (limiter kluczuje anonima po sesji zakupowej). Odpowiedź to surowe {"auth": ...}.
 */
export function createAuthorizer(http: HttpClient) {
  return async (params: { socketId: string; channelName: string }, callback: (error: Error | null, data: { auth: string } | null) => void) => {
    try {
      const { body } = await http.request<{ auth: string }>('/broadcasting/auth', {
        method: 'POST',
        body: { socket_id: params.socketId, channel_name: params.channelName },
        bookingSession: true,
      });
      callback(null, body);
    } catch (error) {
      const status = isApiError(error) ? error.status : 0;
      callback(Object.assign(new Error(`Autoryzacja kanału nieudana (HTTP ${status})`), { status }), null);
    }
  };
}

export function createPusherConnection(options: PusherConnectionOptions): RealtimeConnection {
  const pusher = new Pusher(options.key, {
    ...pusherOptions(options.location ?? window.location),
    channelAuthorization: { customHandler: createAuthorizer(options.http) },
  });
  const listeners = new Set<(state: ConnectionState) => void>();

  pusher.connection.bind('state_change', (states: { current: ConnectionState }) => {
    listeners.forEach((listener) => listener(states.current));
  });

  return {
    get state() {
      return pusher.connection.state as ConnectionState;
    },
    onStateChange(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    subscribe(channelName, handlers) {
      const channel = pusher.subscribe(channelName);
      const succeeded = () => handlers.onSubscribed();
      const failed = (status: { status?: number } | undefined) => handlers.onError(status?.status ?? null);
      channel.bind('pusher:subscription_succeeded', succeeded);
      channel.bind('pusher:subscription_error', failed);
      for (const [event, handler] of Object.entries(handlers.events)) {
        channel.bind(event, handler);
      }
      return () => {
        channel.unbind_all();
        pusher.unsubscribe(channelName);
      };
    },
  };
}
