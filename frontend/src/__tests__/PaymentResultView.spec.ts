import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import { booking, REFERENCE } from './fixtures/checkout';
import { fakeConnection } from './fixtures/realtime';
import { screeningDetails } from './fixtures/seats';

const bookings = vi.hoisted(() => ({ checkout: vi.fn(), abandonPayment: vi.fn(), show: vi.fn() }));
vi.mock('@/api/client', () => ({ bookingsApi: bookings }));
const realtime = vi.hoisted(() => ({ current: null as ReturnType<typeof import('./fixtures/realtime').fakeConnection> | null }));
vi.mock('@/realtime', () => ({ getRealtimeConnection: vi.fn(async () => realtime.current!.connection) }));

const { routes } = await import('@/router');
const { default: PaymentResultView } = await import('@/views/PaymentResultView.vue');

const CHANNEL = `private-bookings.${REFERENCE}`;
const { prices: _prices, ...screening } = screeningDetails;

async function mountView(query = '') {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(`/bookings/${REFERENCE}/payment-result${query}`);
  const wrapper = mount(PaymentResultView, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

describe('ekran wyniku płatności', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
    realtime.current = fakeConnection();
  });

  it('czeka na webhook, a zdarzenie z kanału rezerwacji zamienia ekran na "Płatność przyjęta"', async () => {
    bookings.show
      .mockResolvedValueOnce(booking({ screening }))
      .mockResolvedValue(booking({ screening, status: 'paid', status_label: 'Opłacona', paid_at: '2026-09-17T12:08:00+00:00' }));
    const wrapper = await mountView();

    expect(bookings.show).toHaveBeenCalledWith(REFERENCE);
    expect(wrapper.get('h1').text()).toBe('Czekamy na potwierdzenie płatności');
    expect(wrapper.get('[data-test="payment-result"]').attributes('aria-live')).toBe('polite');

    realtime.current!.emit(CHANNEL, 'booking.status-changed', { reference: REFERENCE, status: 'paid' });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Płatność przyjęta');
    expect(wrapper.get('[data-test="booking-status"]').text()).toBe('Opłacona');
    expect(wrapper.text()).toContain('Barbie');
  });

  it('powrót z nieudanym przekierowaniem: rezerwacja nadal czeka, klient może wrócić do płatności', async () => {
    bookings.show.mockResolvedValue(booking({ screening }));
    const wrapper = await mountView('?redirect_status=failed');

    expect(wrapper.get('h1').text()).toBe('Płatność nie została potwierdzona');
    expect(wrapper.get('a[href="/screenings/334/checkout"]').text()).toBe('Wróć do płatności');
  });

  it.each([
    ['expired', 'Czas na płatność minął'],
    ['cancelled', 'Rezerwacja została anulowana'],
  ] as const)('status %s: płatność nie doszła do skutku, bez pobrania pieniędzy', async (status, text) => {
    bookings.show.mockResolvedValue(booking({ screening, status, status_label: status }));
    const wrapper = await mountView();

    expect(wrapper.get('h1').text()).toBe('Płatność nie doszła do skutku');
    expect(wrapper.text()).toContain(text);
    expect(wrapper.text()).toContain('Nie pobraliśmy żadnych pieniędzy');
    expect(realtime.current!.subscriptions.has(CHANNEL)).toBe(false);
  });

  it('cudza albo nieistniejąca rezerwacja (403/404): jeden komunikat, bez zdradzania, która to sytuacja', async () => {
    bookings.show.mockRejectedValue(new ApiError({ status: 403, code: 'FORBIDDEN', message: 'Brak dostępu.' }));
    const wrapper = await mountView();

    expect(wrapper.get('h1').text()).toBe('Nie znaleziono rezerwacji');
  });
});
