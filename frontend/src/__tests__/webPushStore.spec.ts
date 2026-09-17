import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/api/errors';
import { fakeWebPush, firebase, pushConfig } from './fixtures/push';

const account = vi.hoisted(() => ({ registerDevice: vi.fn(), unregisterDevice: vi.fn(), updateNotificationSettings: vi.fn() }));
vi.mock('@/api/client', () => ({ accountApi: account }));
const loadClientConfig = vi.hoisted(() => vi.fn());
vi.mock('@/api/clientConfig', () => ({ loadClientConfig }));
vi.mock('@/push/webPush', () => ({ browserWebPush: {} }));

const { PUSH_DEVICE_KEY, useWebPushClient, useWebPushStore } = await import('@/stores/webPush');

const DEVICE_ID = '01M2QPPNMXN47ZN3WAQ7WG23VA';
const device = (id = DEVICE_ID) => ({ id, platform: 'web', last_seen_at: '2026-09-17T12:00:00+00:00', created_at: null });
const stored = () => JSON.parse(localStorage.getItem(PUSH_DEVICE_KEY) ?? 'null');

describe('push w tej przeglądarce', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    localStorage.clear();
    setActivePinia(createPinia());
    loadClientConfig.mockResolvedValue(pushConfig());
    account.registerDevice.mockResolvedValue(device());
    account.updateNotificationSettings.mockResolvedValue({ push_enabled: true, push_consent_at: 'x', screening_reminders: true });
  });

  it.each([
    ['push wyłączony w instalacji', () => loadClientConfig.mockResolvedValue(pushConfig(false)), {}, 'unavailable'],
    ['przeglądarka bez obsługi', () => {}, { supported: false }, 'unsupported'],
    ['uprawnienie zablokowane', () => {}, { permission: 'denied' as const }, 'denied'],
    ['jeszcze niewłączony', () => {}, {}, 'off'],
  ])('stan: %s', async (_, arrange, options, expected) => {
    arrange();
    useWebPushClient(fakeWebPush(options));
    const push = useWebPushStore();

    await push.check();

    expect(push.status).toBe(expected);
  });

  it('włączenie z kliknięcia: uprawnienie -> token -> zgoda na koncie -> rejestracja urządzenia', async () => {
    const client = fakeWebPush();
    useWebPushClient(client);
    const push = useWebPushStore();
    await push.check();

    expect(await push.enable()).toBe(true);

    expect(client.requestPermission).toHaveBeenCalledTimes(1);
    expect(client.token).toHaveBeenCalledWith(firebase);
    expect(account.updateNotificationSettings).toHaveBeenCalledWith({ push_enabled: true });
    expect(account.registerDevice).toHaveBeenCalledWith('token-fcm-1', null);
    expect(stored()).toEqual({ id: DEVICE_ID, token: 'token-fcm-1' });
    expect(push.status).toBe('on');
  });

  it('odmowa uprawnienia: nic nie trafia na serwer', async () => {
    useWebPushClient(fakeWebPush({ answer: 'denied' }));
    const push = useWebPushStore();
    await push.check();

    expect(await push.enable()).toBe(false);

    expect(push.status).toBe('denied');
    expect(account.updateNotificationSettings).not.toHaveBeenCalled();
    expect(account.registerDevice).not.toHaveBeenCalled();
  });

  it('nowy token od FCM przy wejściu: rejestracja z replaces starego', async () => {
    localStorage.setItem(PUSH_DEVICE_KEY, JSON.stringify({ id: DEVICE_ID, token: 'stary-token' }));
    account.registerDevice.mockResolvedValue(device('01M2QPPNMXN47ZN3WAQ7WG23VB'));
    useWebPushClient(fakeWebPush({ permission: 'granted', tokens: ['nowy-token'] }));
    const push = useWebPushStore();

    await push.refreshIfRegistered();

    expect(push.status).toBe('on');
    expect(account.registerDevice).toHaveBeenCalledWith('nowy-token', 'stary-token');
    expect(stored()).toEqual({ id: '01M2QPPNMXN47ZN3WAQ7WG23VB', token: 'nowy-token' });
  });

  it('bez zapisanego urządzenia start aplikacji nie ładuje Firebase', async () => {
    const client = fakeWebPush({ permission: 'granted' });
    useWebPushClient(client);

    await useWebPushStore().refreshIfRegistered();

    expect(loadClientConfig).not.toHaveBeenCalled();
    expect(client.token).not.toHaveBeenCalled();
  });

  it('wyłączenie: wyrejestrowanie (404 = już usunięte), usunięcie tokenu, zgoda na koncie bez zmian', async () => {
    localStorage.setItem(PUSH_DEVICE_KEY, JSON.stringify({ id: DEVICE_ID, token: 'token-fcm-1' }));
    account.unregisterDevice.mockRejectedValue(new ApiError({ status: 404, code: 'RESOURCE_NOT_FOUND', message: 'x' }));
    const client = fakeWebPush({ permission: 'granted' });
    useWebPushClient(client);
    const push = useWebPushStore();
    await push.check();

    await push.disable();

    expect(account.unregisterDevice).toHaveBeenCalledWith(DEVICE_ID);
    expect(client.deleteToken).toHaveBeenCalledWith(firebase);
    expect(account.updateNotificationSettings).not.toHaveBeenCalled();
    expect(stored()).toBeNull();
    expect(push.status).toBe('off');
    expect(push.message).toBeNull();
  });

  it('wylogowanie: zapomina urządzenie i token tej przeglądarki', async () => {
    localStorage.setItem(PUSH_DEVICE_KEY, JSON.stringify({ id: DEVICE_ID, token: 'token-fcm-1' }));
    const client = fakeWebPush({ permission: 'granted' });
    useWebPushClient(client);

    await useWebPushStore().forget();

    expect(stored()).toBeNull();
    expect(client.deleteToken).toHaveBeenCalledWith(firebase);
    expect(account.unregisterDevice).not.toHaveBeenCalled();
  });
});
