import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { reactive } from 'vue';
import { routes } from '@/router';
import { installAuthGuards, safeRedirect } from '@/router/guards';

function setup(authenticated: boolean) {
  const auth = reactive({ isAuthenticated: authenticated, init: async () => {} });
  const router = createRouter({ history: createMemoryHistory(), routes });
  installAuthGuards(router, auth);
  return { auth, router };
}

describe('strażnicy tras', () => {
  it('gość na trasie konta trafia na logowanie z adresem powrotu', async () => {
    const { router } = setup(false);

    await router.push('/account?tab=bilety');

    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query.redirect).toBe('/account?tab=bilety');
  });

  it('zalogowany na stronie logowania wraca pod bezpieczny adres z ?redirect=', async () => {
    const { router } = setup(true);

    await router.push('/login?redirect=/account');

    expect(router.currentRoute.value.name).toBe('account');
  });

  it('utrata sesji na ekranie konta przenosi na logowanie z informacją o wygaśnięciu', async () => {
    const { auth, router } = setup(true);
    await router.push('/account');

    auth.isAuthenticated = false;
    await new Promise((resolve) => setTimeout(resolve, 0));
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query).toMatchObject({ redirect: '/account', reason: 'expired' });
  });

  it('?redirect= przyjmuje wyłącznie ścieżki wewnętrzne (bez open redirect)', () => {
    expect(safeRedirect('/account')).toBe('/account');
    expect(safeRedirect('//evil.example/phish')).toBe('/');
    expect(safeRedirect('https://evil.example')).toBe('/');
    expect(safeRedirect('/\\evil.example')).toBe('/');
    expect(safeRedirect(['/account', '/inne'])).toBe('/account');
    expect(safeRedirect(undefined)).toBe('/');
  });
});
