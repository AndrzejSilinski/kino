/*
 * Strażnicy tras (Etap 8, blok D).
 *
 * Strażnik jest wygodą, NIE zabezpieczeniem: dane chroni API (auth:sanctum + Policies).
 * Strażnik tylko nie pokazuje ekranu, który i tak dostałby 401.
 *
 * ?redirect= przyjmujemy wyłącznie jako ścieżkę wewnętrzną. Adres "//evil.example" albo
 * "https://…" po zalogowaniu wyprowadziłby użytkownika na obcą stronę (open redirect).
 */
import { watch } from 'vue';
import type { NavigationGuardWithThis, RouteLocationRaw, Router } from 'vue-router';

export interface AuthState {
  readonly isAuthenticated: boolean;
  init(): Promise<void>;
}

export function safeRedirect(value: unknown): string {
  const candidate = Array.isArray(value) ? value[0] : value;
  if (typeof candidate !== 'string' || !candidate.startsWith('/') || candidate.startsWith('//') || candidate.includes('\\')) {
    return '/';
  }
  return candidate;
}

export function loginLocation(redirect: string, reason?: 'expired'): RouteLocationRaw {
  return { name: 'login', query: { redirect, ...(reason ? { reason } : {}) } };
}

export function createAuthGuard(auth: AuthState): NavigationGuardWithThis<undefined> {
  return async (to) => {
    await auth.init();

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
      return loginLocation(to.fullPath);
    }
    if (to.meta.guestOnly && auth.isAuthenticated) {
      return safeRedirect(to.query.redirect);
    }
    return true;
  };
}

/**
 * Utrata sesji w trakcie pracy (401 z API albo wylogowanie w innej karcie): jeśli bieżący
 * ekran wymaga konta, przechodzimy na logowanie z powrotem na ten sam adres.
 */
export function installAuthGuards(router: Router, auth: AuthState): void {
  router.beforeEach(createAuthGuard(auth));

  watch(
    () => auth.isAuthenticated,
    (authenticated, was) => {
      const current = router.currentRoute.value;
      if (was && !authenticated && current.meta.requiresAuth) {
        void router.replace(loginLocation(current.fullPath, 'expired'));
      }
    },
  );
}
