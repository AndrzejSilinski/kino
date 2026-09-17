import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { ApiError } from '@/api/errors';
import type { NotificationSettings } from '@/api/types';
import { deferred } from './fixtures/seats';

const account = vi.hoisted(() => ({ notificationSettings: vi.fn(), updateNotificationSettings: vi.fn() }));
vi.mock('@/api/client', () => ({ accountApi: account }));

const { default: AccountNotificationsView } = await import('@/views/account/AccountNotificationsView.vue');

const defaults: NotificationSettings = { push_enabled: false, push_consent_at: null, screening_reminders: true };

describe('ustawienia powiadomień', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    account.notificationSettings.mockResolvedValue(defaults);
  });

  it('pokazuje stan z serwera; zmiana czeka na potwierdzenie (bez optymizmu) i wysyła jedno pole', async () => {
    const response = deferred<NotificationSettings>();
    account.updateNotificationSettings.mockReturnValue(response.promise);
    const wrapper = mount(AccountNotificationsView);
    await flushPromises();
    const push = wrapper.get<HTMLInputElement>('[data-test="setting-push"]');
    expect(push.element.checked).toBe(false);
    expect(wrapper.get<HTMLInputElement>('[data-test="setting-reminders"]').element.checked).toBe(true);

    push.element.checked = true;
    await push.trigger('change');

    expect(account.updateNotificationSettings).toHaveBeenCalledWith({ push_enabled: true });
    expect(push.element.checked).toBe(false);
    expect(push.attributes('disabled')).toBeDefined();

    response.resolve({ ...defaults, push_enabled: true, push_consent_at: '2026-09-17T12:00:00+00:00' });
    await flushPromises();
    expect(push.element.checked).toBe(true);
    expect(push.attributes('disabled')).toBeUndefined();
  });

  it('błąd zapisu: przełącznik zostaje w stanie z serwera i widać komunikat', async () => {
    account.updateNotificationSettings.mockRejectedValue(new ApiError({ status: 0, code: 'NETWORK_ERROR', message: 'x' }));
    const wrapper = mount(AccountNotificationsView);
    await flushPromises();
    const reminders = wrapper.get<HTMLInputElement>('[data-test="setting-reminders"]');

    reminders.element.checked = false;
    await reminders.trigger('change');
    await flushPromises();

    expect(reminders.element.checked).toBe(true);
    expect(wrapper.get('[data-test="settings-error"]').text()).toBe('Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.');
  });
});
