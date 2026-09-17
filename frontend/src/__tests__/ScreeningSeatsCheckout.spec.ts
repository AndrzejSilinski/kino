import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { booking, pendingCart, REFERENCE } from './fixtures/checkout';
import { cartOf, mapSeat, snapshot } from './fixtures/seats';

const seats = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
const bookings = vi.hoisted(() => ({ checkout: vi.fn(), abandonPayment: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: seats, bookingsApi: bookings }));
vi.mock('@/api/clientConfig', () => ({ loadClientConfig: vi.fn(async () => ({ booking: { max_seats_per_session: 10 } })) }));

const { routes } = await import('@/router');
const { default: ScreeningSeatsView } = await import('@/views/ScreeningSeatsView.vue');

const A1 = 891;
const A2 = 892;

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push('/screenings/334/seats');
  const wrapper = mount(ScreeningSeatsView, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('plan sali a checkout (blok H2)', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('"Przejdź do podsumowania" prowadzi na ekran checkoutu tego seansu', async () => {
    seats.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' })]));
    seats.cart.mockResolvedValue(cartOf([A1]));
    const { wrapper, router } = await mountView();

    await wrapper.get('[data-test="go-to-checkout"]').trigger('click');

    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/screenings/334/checkout'));
  });

  it('rozpoczęta płatność zamraża plan: miejsca wyłączone, bez czyszczenia i bez drugiego checkoutu', async () => {
    seats.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' }), mapSeat({ position: { x: 2, y: 1 } })]));
    seats.cart.mockResolvedValue(pendingCart([A1]));
    const { wrapper } = await mountView();

    expect(wrapper.get('[data-test="pending-payment"]').text()).toContain('rozpoczętą płatność');
    expect(wrapper.get(`[data-seat-id="${A2}"]`).attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-test="go-to-checkout"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('Wyczyść wybór');
  });

  it('rezygnacja z planu sali: płatność anulowana, koszyk pusty, plan znów klikalny', async () => {
    seats.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' }), mapSeat({ position: { x: 2, y: 1 } })]));
    seats.cart.mockResolvedValueOnce(pendingCart([A1])).mockResolvedValue(cartOf([]));
    bookings.abandonPayment.mockResolvedValue(booking({ status: 'cancelled', status_label: 'Anulowana' }));
    const { wrapper } = await mountView();
    seats.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 } })], 8));

    await wrapper.get('[data-test="abandon-payment"]').trigger('click');
    await flushPromises();

    expect(bookings.abandonPayment).toHaveBeenCalledWith(REFERENCE);
    expect(wrapper.find('[data-test="pending-payment"]').exists()).toBe(false);
    expect(wrapper.get('[data-test="cart-notice"]').text()).toContain('Zrezygnowano z płatności');
    expect(wrapper.get(`[data-seat-id="${A2}"]`).attributes('disabled')).toBeUndefined();
  });
});
