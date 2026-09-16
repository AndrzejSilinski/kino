import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router';
import { SELECTED_CINEMA_KEY } from '@/stores/cinema';

describe('trasa startowa z zapamiętanym kinem', () => {
  beforeEach(() => localStorage.clear());

  it('zapamiętane kino otwiera od razu repertuar; ?change=1 pokazuje listę kin', async () => {
    localStorage.setItem(SELECTED_CINEMA_KEY, 'krakow-kino-wisla');
    const router = createRouter({ history: createMemoryHistory(), routes });

    await router.push('/');
    expect(router.currentRoute.value.fullPath).toBe('/cinemas/krakow-kino-wisla');

    await router.push('/?change=1');
    expect(router.currentRoute.value.name).toBe('home');
  });
});
