import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import { screening } from './fixtures/catalog';

const api = vi.hoisted(() => ({ cinemas: vi.fn(), cinema: vi.fn(), screeningDates: vi.fn(), screeningsForDay: vi.fn(), screening: vi.fn() }));
vi.mock('@/api/client', () => ({ catalogApi: api }));

const { routes } = await import('@/router');
const { default: RepertoireView } = await import('@/views/RepertoireView.vue');
const { SELECTED_CINEMA_KEY } = await import('@/stores/cinema');

const cinema = { id: 3, slug: 'gdansk-kino-baltyk', name: 'Kino Bałtyk', city: 'Gdańsk', address: 'al. Grunwaldzka 82', timezone: 'Europe/Warsaw' };

async function mountAt(url: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(url);
  const wrapper = mount(RepertoireView, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('ekran repertuaru', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetAllMocks();
    setActivePinia(createPinia());
    api.cinema.mockResolvedValue(cinema);
    api.screeningDates.mockResolvedValue({ today: '2026-09-16', timezone: 'Europe/Warsaw', dates: [{ date: '2026-09-16', screenings_count: 1 }, { date: '2026-09-17', screenings_count: 2 }] });
  });

  it('ładuje dzień z adresu, zapamiętuje kino i pokazuje godziny w strefie kina', async () => {
    api.screeningsForDay.mockResolvedValue([screening({ id: 1, starts_at: '2026-09-17T19:30:00+02:00' })]);

    const { wrapper } = await mountAt('/cinemas/gdansk-kino-baltyk?date=2026-09-17');

    expect(api.screeningsForDay).toHaveBeenLastCalledWith('gdansk-kino-baltyk', '2026-09-17', expect.any(AbortSignal));
    expect(wrapper.get('h1').text()).toBe('Kino Bałtyk');
    expect(wrapper.get('[data-test="showtime-1"]').text()).toContain('19:30');
    expect(localStorage.getItem(SELECTED_CINEMA_KEY)).toBe('gdansk-kino-baltyk');
  });

  it('wolniejsza odpowiedź dla wcześniej klikniętego dnia nie nadpisuje nowszego repertuaru', async () => {
    const pending = new Map<string, (items: ReturnType<typeof screening>[]) => void>();
    api.screeningsForDay.mockImplementation((_slug: string, date: string) => new Promise((resolve) => pending.set(date, resolve)));
    const { wrapper, router } = await mountAt('/cinemas/gdansk-kino-baltyk?date=2026-09-16');

    await router.replace({ query: { date: '2026-09-17' } });
    await flushPromises();
    pending.get('2026-09-17')?.([screening({ id: 17, starts_at: '2026-09-17T18:00:00+02:00' })]);
    await flushPromises();
    pending.get('2026-09-16')?.([screening({ id: 16, starts_at: '2026-09-16T12:00:00+02:00' })]);
    await flushPromises();

    expect(wrapper.find('[data-test="showtime-17"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="showtime-16"]').exists()).toBe(false);
  });

  it('kino wycofane z oferty (404): komunikat, link do wyboru kina i zapomniany wybór', async () => {
    localStorage.setItem(SELECTED_CINEMA_KEY, 'stare-kino');
    api.cinema.mockRejectedValue(new ApiError({ status: 404, code: 'RESOURCE_NOT_FOUND', message: 'Nie znaleziono zasobu.' }));

    const { wrapper } = await mountAt('/cinemas/stare-kino');

    expect(wrapper.get('h1').text()).toBe('Kino niedostępne');
    expect(localStorage.getItem(SELECTED_CINEMA_KEY)).toBeNull();
  });
});
