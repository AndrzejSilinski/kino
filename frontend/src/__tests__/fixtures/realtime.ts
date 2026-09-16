import type { ConnectionState, RealtimeConnection, SubscriptionHandlers } from '@/realtime/connection';

/** Atrapa połączenia WebSocket: test sam "wysyła" zdarzenia i zmienia stan połączenia. */
export function fakeConnection() {
  const subscriptions = new Map<string, SubscriptionHandlers>();
  const listeners = new Set<(state: ConnectionState) => void>();
  let state: ConnectionState = 'connecting';
  let subscribeCount = 0;

  const connection: RealtimeConnection = {
    get state() {
      return state;
    },
    onStateChange(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    subscribe(channel, handlers) {
      subscribeCount++;
      subscriptions.set(channel, handlers);
      return () => subscriptions.delete(channel);
    },
  };

  return {
    connection,
    subscriptions,
    get subscribeCount() {
      return subscribeCount;
    },
    setState(next: ConnectionState) {
      state = next;
      listeners.forEach((listener) => listener(next));
    },
    subscribed(channel: string) {
      subscriptions.get(channel)?.onSubscribed();
    },
    fail(channel: string, status: number) {
      subscriptions.get(channel)?.onError(status);
    },
    emit(channel: string, event: string, data: unknown) {
      subscriptions.get(channel)?.events[event]?.(data);
    },
  };
}
