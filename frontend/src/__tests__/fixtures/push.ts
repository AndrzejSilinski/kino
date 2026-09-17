import { vi } from 'vitest';
import type { ClientConfig, FirebaseWebConfig } from '@/api/clientConfig';
import type { WebPushClient } from '@/push/webPush';

export const firebase: FirebaseWebConfig = { api_key: 'klucz-web', app_id: '1:1:web:1', project_id: 'kino-test', messaging_sender_id: '1', vapid_public_key: 'BKlucz' };

export function pushConfig(enabled = true): Pick<ClientConfig, 'push'> {
  return { push: enabled ? { enabled: true, firebase } : { enabled: false } };
}

/** Atrapa przeglądarki: uprawnienie i tokeny sterowane z testu. */
export function fakeWebPush(options: { supported?: boolean; permission?: NotificationPermission; answer?: NotificationPermission; tokens?: string[] } = {}) {
  let permission = options.permission ?? 'default';
  const tokens = [...(options.tokens ?? ['token-fcm-1'])];
  const client = {
    supported: vi.fn(async () => options.supported ?? true),
    permission: vi.fn(() => permission),
    requestPermission: vi.fn(async () => {
      permission = options.answer ?? 'granted';
      return permission;
    }),
    token: vi.fn(async () => (tokens.length > 1 ? tokens.shift()! : tokens[0]!)),
    deleteToken: vi.fn(async () => {}),
  } satisfies WebPushClient;
  return client;
}
