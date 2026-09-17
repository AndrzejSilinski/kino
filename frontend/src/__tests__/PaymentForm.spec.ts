import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { FAKE_CLIENT_SECRET } from './fixtures/checkout';
import { deferred } from './fixtures/seats';

const paymentUi = vi.hoisted(() => ({ mount: vi.fn(), confirm: vi.fn(), destroy: vi.fn() }));
const createStripePaymentUi = vi.hoisted(() => vi.fn());
vi.mock('@/payments/stripe', () => ({ createStripePaymentUi }));

const { default: PaymentForm } = await import('@/components/checkout/PaymentForm.vue');

const RETURN_URL = 'http://localhost:3000/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/payment-result';

function mountForm(props: Record<string, unknown> = {}) {
  return mount(PaymentForm, {
    props: { publishableKey: 'klucz-publiczny', clientSecret: FAKE_CLIENT_SECRET, returnUrl: RETURN_URL, amount: '17,60 zł', ...props },
    attachTo: document.body,
  });
}

describe('formularz płatności', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    paymentUi.mount.mockResolvedValue(undefined);
    createStripePaymentUi.mockResolvedValue(paymentUi);
  });

  it('montuje Payment Element w kontenerze i odblokowuje "Zapłać" dopiero, gdy formularz jest gotowy', async () => {
    const ready = deferred<void>();
    paymentUi.mount.mockReturnValue(ready.promise);
    const wrapper = mountForm();
    await flushPromises();

    expect(createStripePaymentUi).toHaveBeenCalledWith({ publishableKey: 'klucz-publiczny', clientSecret: FAKE_CLIENT_SECRET, dark: false });
    expect(paymentUi.mount).toHaveBeenCalledWith(wrapper.get('[data-test="payment-element"]').element);
    expect(wrapper.get('[data-test="pay"]').attributes('disabled')).toBeDefined();

    ready.resolve();
    await flushPromises();
    expect(wrapper.get('[data-test="pay"]').attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });

  it('potwierdzenie z return_url; sukces zgłasza "confirmed" ze statusem płatności', async () => {
    paymentUi.confirm.mockResolvedValue({ kind: 'confirmed', status: 'requires_capture' });
    const wrapper = mountForm();
    await flushPromises();

    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(paymentUi.confirm).toHaveBeenCalledWith(RETURN_URL);
    expect(wrapper.emitted('confirmed')).toEqual([['requires_capture']]);
    expect(wrapper.emitted('busy')).toEqual([[true], [false]]);
    wrapper.unmount();
  });

  it('odrzucona karta: komunikat jako alert, formularz gotowy do ponownej próby', async () => {
    paymentUi.confirm.mockResolvedValue({ kind: 'failed', message: 'Twoja karta została odrzucona.', code: 'card_declined' });
    const wrapper = mountForm();
    await flushPromises();

    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.get('[data-test="payment-error"]').attributes('role')).toBe('alert');
    expect(wrapper.get('[data-test="payment-error"]').text()).toBe('Twoja karta została odrzucona.');
    expect(wrapper.emitted('confirmed')).toBeUndefined();
    expect(wrapper.get('[data-test="pay"]').attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });

  it('Stripe.js się nie wczytał (bloker, brak sieci): komunikat i ponowne ładowanie', async () => {
    createStripePaymentUi.mockRejectedValueOnce(new Error('zablokowany skrypt'));
    const wrapper = mountForm();
    await flushPromises();

    expect(wrapper.find('[data-test="pay"]').exists()).toBe(false);
    await wrapper.get('[data-test="payment-reload"]').trigger('click');
    await flushPromises();

    expect(createStripePaymentUi).toHaveBeenCalledTimes(2);
    expect(wrapper.get('[data-test="pay"]').attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });

  it('zablokowany z zewnątrz (rezygnacja w toku) nie wysyła płatności; odmontowanie niszczy formularz', async () => {
    const wrapper = mountForm({ disabled: true });
    await flushPromises();

    await wrapper.get('form').trigger('submit');
    expect(paymentUi.confirm).not.toHaveBeenCalled();

    wrapper.unmount();
    expect(paymentUi.destroy).toHaveBeenCalled();
  });
});
