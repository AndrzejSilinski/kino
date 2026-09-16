import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const api = vi.hoisted(() => ({ cinemas: vi.fn() }));
vi.mock('@/api/client', () => ({ catalogApi: api }));

const { SELECTED_CINEMA_KEY, readRememberedCinema, useCinemaStore } = await import('@/stores/cinema');

describe('wybrane kino', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('zapamiętuje wybór w localStorage i odczytuje go po ponownym uruchomieniu', async () => {
    api.cinemas.mockResolvedValue([{ city: 'Gdańsk', cinemas: [{ id: 3, slug: 'gdansk-kino-baltyk', name: 'Kino Bałtyk', city: 'Gdańsk', address: 'al. Grunwaldzka 82', timezone: 'Europe/Warsaw' }] }]);
    const store = useCinemaStore();
    await store.loadCinemas();

    store.select('gdansk-kino-baltyk');

    expect(localStorage.getItem(SELECTED_CINEMA_KEY)).toBe('gdansk-kino-baltyk');
    expect(store.selectedCinema?.name).toBe('Kino Bałtyk');
    setActivePinia(createPinia());
    expect(useCinemaStore().selectedSlug).toBe('gdansk-kino-baltyk');
  });

  it('nie zapamiętuje wartości spoza formatu sluga i zapomina kino wycofane z oferty', () => {
    const store = useCinemaStore();

    store.select('../../admin');
    expect(localStorage.getItem(SELECTED_CINEMA_KEY)).toBeNull();

    store.select('krakow-kino-wisla');
    store.forget();
    expect(readRememberedCinema()).toBeNull();
  });
});
