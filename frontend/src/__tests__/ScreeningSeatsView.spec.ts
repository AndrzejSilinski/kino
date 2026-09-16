import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import { cartOf, mapSeat, snapshot } from './fixtures/seats';

const api = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: api }));
vi.mock('@/api/clientConfig', () => ({ loadClientConfig: vi.fn(async () => ({ booking: { max_seats_per_session: 10 } })) }));

const { routes } = await import('@/router');
const { default: ScreeningSeatsView } = await import('@/views/ScreeningSeatsView.vue');

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push('/screenings/334/seats');
  const wrapper = mount(ScreeningSeatsView, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

describe('ekran wyboru miejsc', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 } })]));
  });

  it('po potwierdzeniu serwera pokazuje miejsce w koszyku, sumę i odliczanie', async () => {
    api.lock.mockResolvedValue(cartOf([891], 600));
    const wrapper = await mountView();

    await wrapper.get('[data-seat-id="891"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-test="cart-total"]').text()).toBe('17,60 zł');
    expect(wrapper.get('[data-test="countdown"]').text()).toBe('10:00');
    expect(wrapper.get('[data-seat-id="891"]').attributes('aria-pressed')).toBe('true');
  });

  it('odmowa serwera (409) jest ogłaszana jako alert, a miejsce nie trafia do koszyka', async () => {
    api.lock.mockRejectedValue(new ApiError({ status: 409, code: 'SEATS_UNAVAILABLE', message: 'Miejsca A2 zostały właśnie zajęte przez kogoś innego.', context: { seat_ids: [892] } }));
    const wrapper = await mountView();
    // Po 409 widok pobiera świeży plan — serwer pokazuje już cudzą blokadę.
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 }, status: 'held' })], 6));

    await wrapper.get('[data-seat-id="892"]').trigger('click');
    await flushPromises();

    const notice = wrapper.get('[data-test="cart-notice"]');
    expect(notice.attributes('role')).toBe('alert');
    expect(notice.text()).toContain('zajęte przez kogoś innego');
    expect(wrapper.text()).toContain('Nie wybrano jeszcze miejsc.');
    expect(wrapper.get('[data-seat-id="892"]').attributes('disabled')).toBeDefined();
  });
});
