import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mapSeat, snapshot } from './fixtures/seats';
import { fakeConnection } from './fixtures/realtime';

const fake = fakeConnection();
const api = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: api }));
vi.mock('@/api/clientConfig', () => ({ loadClientConfig: vi.fn(async () => ({ booking: { max_seats_per_session: 10 } })) }));
vi.mock('@/realtime', () => ({ getRealtimeConnection: vi.fn(async () => fake.connection) }));

const { routes } = await import('@/router');
const { default: ScreeningSeatsView } = await import('@/views/ScreeningSeatsView.vue');

describe('plan sali na żywo (widok)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 } })], 5));
  });

  it('blokada innego klienta zmienia miejsce natychmiast, bez odświeżania; wyjście z widoku wypisuje z kanału', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/screenings/334/seats');
    const wrapper = mount(ScreeningSeatsView, { global: { plugins: [router] } });
    await flushPromises();
    fake.subscribed('private-screenings.334');
    await flushPromises();

    expect(wrapper.find('[data-test="realtime-live"]').exists()).toBe(true);
    const requestsBefore = api.seatMap.mock.calls.length;

    fake.emit('private-screenings.334', 'seats.changed', { screening_id: 334, version: 6, seats: { held: [892] } });
    await flushPromises();

    const seat = wrapper.get('[data-seat-id="892"]');
    expect(seat.classes()).toContain('seat-held');
    expect(seat.attributes('disabled')).toBeDefined();
    expect(api.seatMap.mock.calls.length).toBe(requestsBefore);

    wrapper.unmount();
    expect(fake.subscriptions.has('private-screenings.334')).toBe(false);
  });
});
