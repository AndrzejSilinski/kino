import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/api/errors';
import type { User } from '@/api/types';

const api = vi.hoisted(() => ({
  login: vi.fn(),
  register: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}));

vi.mock('@/api/client', () => ({ authApi: api }));

const { TOKEN_KEY, useAuthStore } = await import('@/stores/auth');

const user: User = { id: 7, name: 'Anna Nowak', email: 'anna@example.com', role: 'customer', role_label: 'Klient', created_at: null };
const unauthenticated = () => new ApiError({ status: 401, code: 'UNAUTHENTICATED', message: 'Wymagane jest zalogowanie.' });

describe('store sesji konta', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('logowanie zapisuje token i użytkownika, także w localStorage', async () => {
    api.login.mockResolvedValue({ user, token: '3|nowy', token_type: 'Bearer' });
    const auth = useAuthStore();

    await auth.login('anna@example.com', 'haslo1234');

    expect(auth.isAuthenticated).toBe(true);
    expect(auth.user?.name).toBe('Anna Nowak');
    expect(localStorage.getItem(TOKEN_KEY)).toBe('3|nowy');
  });

  it('wylogowanie czyści sesję nawet wtedy, gdy serwer jest nieosiągalny', async () => {
    localStorage.setItem(TOKEN_KEY, '3|stary');
    api.logout.mockRejectedValue(new ApiError({ status: 0, code: 'NETWORK_ERROR', message: 'Brak sieci' }));
    const auth = useAuthStore();

    await auth.logout();

    expect(auth.isAuthenticated).toBe(false);
    expect(localStorage.getItem(TOKEN_KEY)).toBeNull();
  });

  it('start z zapisanym tokenem: 401 z /auth/me czyści sesję', async () => {
    localStorage.setItem(TOKEN_KEY, '3|wygasly');
    api.me.mockRejectedValue(unauthenticated());
    const auth = useAuthStore();

    await auth.init();

    expect(auth.isAuthenticated).toBe(false);
    expect(auth.ready).toBe(true);
  });

  it('start bez sieci zostawia token — chwilowa awaria nie wylogowuje', async () => {
    localStorage.setItem(TOKEN_KEY, '3|dobry');
    api.me.mockRejectedValue(new ApiError({ status: 0, code: 'NETWORK_ERROR', message: 'Brak sieci' }));
    const auth = useAuthStore();

    await auth.init();

    expect(auth.token).toBe('3|dobry');
    expect(auth.user).toBeNull();
  });

  it('wylogowanie w innej karcie (zdarzenie storage) wylogowuje tę kartę', async () => {
    localStorage.setItem(TOKEN_KEY, '3|wspolny');
    api.me.mockResolvedValue(user);
    const auth = useAuthStore();
    await auth.init();

    localStorage.removeItem(TOKEN_KEY);
    auth.syncFromStorage({ key: TOKEN_KEY });

    expect(auth.isAuthenticated).toBe(false);
    expect(auth.user).toBeNull();
  });
});
