import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { Booking, Paginated } from '@/api/types';
import { booking } from './fixtures/checkout';
import { screeningDetails } from './fixtures/seats';

const bookings = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/client', () => ({ bookingsApi: bookings }));

const { routes } = await import('@/router');
const { default: AccountBookingsView } = await import('@/views/account/AccountBookingsView.vue');
const { prices: _prices, ...screening } = screeningDetails;

const REF_PAID = '01M2QPPNMXN47ZN3WAQ7WG23VA';
const REF_REFUNDED = '01M2QPPNMXN47ZN3WAQ7WG23VB';

function page(items: Booking[], current = 1, last = 1): Paginated<Booking> {
  return { data: items, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: current, last_page: last, per_page: 10, total: items.length } };
}

async function mountView(path = '/account') {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  const wrapper = mount(AccountBookingsView, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('historia zakupów', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('rezerwacje z seansem, statusem, kwotą i odnośnikiem do biletów albo stanu płatności', async () => {
    bookings.list.mockResolvedValue(page([
      booking({ reference: REF_PAID, screening, status: 'paid', status_label: 'Opłacona', tickets_count: 2 }),
      booking({ screening }),
      booking({ reference: REF_REFUNDED, screening, status: 'refunded', status_label: 'Zwrócona', cancellation: { cancelled_at: '2026-09-17T10:00:00+00:00', refund: 'refunded' } }),
    ]));
    const { wrapper } = await mountView();

    expect(bookings.list).toHaveBeenCalledWith(1, expect.any(AbortSignal));
    const paid = wrapper.get(`[data-test="booking-${REF_PAID}"]`);
    expect(paid.text()).toContain('Barbie');
    expect(paid.text()).toContain('biletów: 2');
    expect(paid.get('a').attributes('href')).toBe(`/bookings/${REF_PAID}`);
    expect(wrapper.get('[data-test="booking-01M2QM60X2F0WC7SXBC2E7Q7VA"] a').attributes('href')).toBe('/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/payment-result');
    expect(wrapper.get(`[data-test="booking-${REF_REFUNDED}"]`).text()).toContain('Pieniądze zwrócone');
  });

  it('stronicowanie: numer strony w adresie, przyciski przełączają strony', async () => {
    bookings.list.mockResolvedValue(page([booking({ screening })], 2, 3));
    const { wrapper, router } = await mountView('/account?page=2');

    expect(bookings.list).toHaveBeenCalledWith(2, expect.any(AbortSignal));
    expect(wrapper.text()).toContain('Strona 2 z 3');

    await wrapper.get('[data-test="next-page"]').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.page).toBe('3');
    expect(bookings.list).toHaveBeenLastCalledWith(3, expect.any(AbortSignal));

    await wrapper.get('[data-test="prev-page"]').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.page).toBe('2');
  });

  it('brak rezerwacji: zachęta do wyboru seansu; zła wartość ?page= to strona 1', async () => {
    bookings.list.mockResolvedValue(page([]));
    const { wrapper } = await mountView('/account?page=abc');

    expect(bookings.list).toHaveBeenCalledWith(1, expect.any(AbortSignal));
    expect(wrapper.get('[data-test="bookings-empty"]').text()).toContain('Nie masz jeszcze żadnych rezerwacji.');
  });
});
