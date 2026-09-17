import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import { booking, checkoutResult, pendingCart, REFERENCE } from './fixtures/checkout';
import { cartOf, mapSeat, snapshot } from './fixtures/seats';

const seats = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
const bookings = vi.hoisted(() => ({ checkout: vi.fn(), abandonPayment: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: seats, bookingsApi: bookings }));

const { routes } = await import('@/router');
const { default: CheckoutView } = await import('@/views/CheckoutView.vue');

const A1 = 891;
const mine = () => snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' }), mapSeat({ position: { x: 2, y: 1 } })]);

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push('/screenings/334/checkout');
  const wrapper = mount(CheckoutView, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('ekran podsumowania i płatności', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
    seats.seatMap.mockResolvedValue(mine());
  });

  it('podsumowanie -> "Przejdź do płatności" -> checkout i miejsce na formularz z kwotą i terminem', async () => {
    seats.cart.mockResolvedValueOnce(cartOf([A1], 420)).mockResolvedValue(pendingCart([A1], 598));
    bookings.checkout.mockResolvedValue({ created: true, result: checkoutResult(598) });
    const { wrapper } = await mountView();

    expect(wrapper.get('h1').text()).toBe('Podsumowanie zamówienia');
    expect(wrapper.get('[data-test="countdown"]').text()).toBe('7:00');
    expect(wrapper.find('[data-test="payment-slot"]').exists()).toBe(false);

    await wrapper.get('[data-test="start-payment"]').trigger('click');
    await flushPromises();

    expect(bookings.checkout).toHaveBeenCalledWith(334);
    expect(wrapper.get('h1').text()).toBe('Płatność');
    expect(wrapper.get('[data-test="payment-total"]').text()).toBe('17,60 zł');
    expect(wrapper.get('[data-test="countdown"]').text()).toBe('9:58');
    expect(wrapper.find('[data-test="payment-slot"]').exists()).toBe(true);
    // Koszyk odświeżony po checkoucie: plan sali po powrocie będzie wiedział o płatności.
    expect(seats.cart).toHaveBeenCalledTimes(2);
  });

  it('F5 w trakcie płatności: koszyk z pending_booking wznawia płatność bez kliknięcia', async () => {
    seats.cart.mockResolvedValue(pendingCart([A1]));
    bookings.checkout.mockResolvedValue({ created: false, result: checkoutResult() });
    const { wrapper } = await mountView();

    expect(bookings.checkout).toHaveBeenCalledTimes(1);
    expect(wrapper.find('[data-test="payment-slot"]').exists()).toBe(true);
  });

  it('pusty koszyk: nic do opłacenia i odnośnik do planu sali', async () => {
    seats.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } })]));
    const { wrapper } = await mountView();

    expect(seats.cart).not.toHaveBeenCalled();
    expect(wrapper.get('[data-test="checkout-empty"]').text()).toContain('Koszyk jest pusty');
    expect(wrapper.find('[data-test="start-payment"]').exists()).toBe(false);
  });

  it('dobrane miejsce (409 BOOKING_ALREADY_PENDING): zwolnienie dobranych i powrót do płatności', async () => {
    seats.cart.mockResolvedValue(cartOf([A1, 892]));
    bookings.checkout
      .mockRejectedValueOnce(new ApiError({ status: 409, code: 'BOOKING_ALREADY_PENDING', message: 'Masz już rozpoczętą płatność za te miejsca.', context: { booking_reference: REFERENCE } }))
      .mockResolvedValue({ created: false, result: checkoutResult() });
    seats.releaseAll.mockResolvedValue(pendingCart([A1]));
    const { wrapper } = await mountView();

    await wrapper.get('[data-test="start-payment"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="checkout-problem"]').attributes('role')).toBe('alert');

    await wrapper.get('[data-test="release-extra"]').trigger('click');
    await flushPromises();

    expect(seats.releaseAll).toHaveBeenCalledWith(334);
    expect(bookings.checkout).toHaveBeenCalledTimes(2);
    expect(wrapper.find('[data-test="checkout-problem"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="payment-slot"]').exists()).toBe(true);
  });

  it('operator płatności niedostępny (503): komunikat i ponowienie tego samego żądania', async () => {
    seats.cart.mockResolvedValue(cartOf([A1]));
    bookings.checkout
      .mockRejectedValueOnce(new ApiError({ status: 503, code: 'PAYMENT_PROVIDER_UNAVAILABLE', message: 'Operator płatności jest chwilowo niedostępny.' }))
      .mockResolvedValue({ created: true, result: checkoutResult() });
    const { wrapper } = await mountView();

    await wrapper.get('[data-test="start-payment"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-test="retry"]').trigger('click');
    await flushPromises();

    expect(bookings.checkout).toHaveBeenCalledTimes(2);
    expect(wrapper.find('[data-test="payment-slot"]').exists()).toBe(true);
  });

  it('rezygnacja z płatności wraca do planu sali z pustym koszykiem', async () => {
    seats.cart.mockResolvedValueOnce(pendingCart([A1])).mockResolvedValue(cartOf([]));
    bookings.checkout.mockResolvedValue({ created: false, result: checkoutResult() });
    bookings.abandonPayment.mockResolvedValue(booking({ status: 'cancelled', status_label: 'Anulowana' }));
    const { wrapper, router } = await mountView();

    await wrapper.get('[data-test="abandon-payment"]').trigger('click');
    await flushPromises();

    expect(bookings.abandonPayment).toHaveBeenCalledWith(REFERENCE);
    // Trasa ładuje widok leniwie (import()), więc nawigacja kończy się po kilku mikrozadaniach.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('screening-seats'));
  });
});
