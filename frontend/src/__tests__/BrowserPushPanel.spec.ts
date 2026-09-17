import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { fakeWebPush, pushConfig } from './fixtures/push';

const account = vi.hoisted(() => ({ registerDevice: vi.fn(), unregisterDevice: vi.fn(), updateNotificationSettings: vi.fn() }));
vi.mock('@/api/client', () => ({ accountApi: account }));
vi.mock('@/api/clientConfig', () => ({ loadClientConfig: vi.fn(async () => pushConfig()) }));
vi.mock('@/push/webPush', () => ({ browserWebPush: {} }));

const { useWebPushClient } = await import('@/stores/webPush');
const { default: BrowserPushPanel } = await import('@/components/account/BrowserPushPanel.vue');

describe('panel powiadomień przeglądarki', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    localStorage.clear();
    setActivePinia(createPinia());
  });

  it('włączenie z przycisku zgłasza zmianę (widok odświeża zgodę na koncie)', async () => {
    account.registerDevice.mockResolvedValue({ id: '01M2QPPNMXN47ZN3WAQ7WG23VA', platform: 'web', last_seen_at: 'x', created_at: null });
    account.updateNotificationSettings.mockResolvedValue({});
    useWebPushClient(fakeWebPush());
    const wrapper = mount(BrowserPushPanel);
    await flushPromises();

    await wrapper.get('[data-test="push-enable"]').trigger('click');
    await flushPromises();

    expect(wrapper.emitted('changed')).toHaveLength(1);
    expect(wrapper.find('[data-test="push-on"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="push-disable"]').exists()).toBe(true);
  });

  it('zablokowane uprawnienie: instrukcja zamiast przycisku (strona nie może zapytać ponownie)', async () => {
    useWebPushClient(fakeWebPush({ permission: 'denied' }));
    const wrapper = mount(BrowserPushPanel);
    await flushPromises();

    expect(wrapper.get('[data-test="push-denied"]').text()).toContain('ustawieniach przeglądarki');
    expect(wrapper.find('[data-test="push-enable"]').exists()).toBe(false);
  });
});
