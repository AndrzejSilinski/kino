/*
 * Sesja konta w SPA (Etap 8, blok D).
 *
 * TOKEN W localStorage (decyzja z planu Etapu 8): przetrwa odświeżenie i nowe karty.
 * Ryzyko to XSS — dlatego CSP bez wyjątków na dokumencie SPA, v-html tylko dla treści
 * oczyszczonej na serwerze i tokeny z terminem ważności (sanctum.expiration).
 * Ciasteczko HttpOnly nie chroniłoby przed XSS działającym w imieniu użytkownika,
 * tylko przed wykradzeniem tokenu, a zmieniałoby decyzję 16 (bearer wspólny z Flutterem).
 *
 * Wiele kart: zdarzenie `storage` przychodzi do POZOSTAŁYCH kart, gdy jedna zmieni token.
 * Wylogowanie w jednej karcie wylogowuje wszystkie; logowanie w jednej loguje pozostałe.
 */
import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { authApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { RegisterPayload, User } from '@/api/types';
import { readItem, removeItem, safeStorage, writeItem } from '@/lib/storage';

export const TOKEN_KEY = 'cinema.auth.token';

export const useAuthStore = defineStore('auth', () => {
  const storage = safeStorage('local');
  const token = ref<string | null>(readItem(storage, TOKEN_KEY));
  const user = ref<User | null>(null);
  const ready = ref(false);
  let initPromise: Promise<void> | null = null;

  const isAuthenticated = computed(() => token.value !== null);

  function setSession(newToken: string, newUser: User): void {
    token.value = newToken;
    user.value = newUser;
    writeItem(storage, TOKEN_KEY, newToken);
  }

  /** Czyści sesję w TEJ karcie i w magazynie (inne karty dostaną zdarzenie storage). */
  function clearSession(): void {
    token.value = null;
    user.value = null;
    removeItem(storage, TOKEN_KEY);
  }

  async function loadUser(): Promise<void> {
    try {
      user.value = await authApi.me();
    } catch (error) {
      // 401: token wygasł albo unieważniono go na innym urządzeniu (zmiana hasła, wylogowanie).
      // Brak sieci: token zostaje — użytkownik nie traci sesji przez chwilową awarię.
      if (isApiError(error) && error.status === 401) {
        clearSession();
      }
    }
  }

  /** Jednorazowo przy starcie aplikacji; strażnik tras czeka na wynik. */
  function init(): Promise<void> {
    initPromise ??= (async () => {
      if (token.value) {
        await loadUser();
      }
      ready.value = true;
    })();
    return initPromise;
  }

  async function login(email: string, password: string): Promise<void> {
    const result = await authApi.login(email, password);
    setSession(result.token, result.user);
  }

  async function register(payload: RegisterPayload): Promise<void> {
    const result = await authApi.register(payload);
    setSession(result.token, result.user);
  }

  /** Token kasujemy lokalnie zawsze — także gdy serwer jest nieosiągalny albo token już wygasł. */
  async function logout(): Promise<void> {
    try {
      if (token.value) {
        await authApi.logout();
      }
    } catch {
      // Nie blokujemy wylogowania: po stronie serwera token i tak wygaśnie (sanctum.expiration).
    } finally {
      clearSession();
    }
  }

  /** Zmiana tokenu w innej karcie (window 'storage'). */
  function syncFromStorage(event: Pick<StorageEvent, 'key'>): void {
    if (event.key !== TOKEN_KEY && event.key !== null) {
      return;
    }
    const next = readItem(storage, TOKEN_KEY);
    if (next === token.value) {
      return;
    }
    token.value = next;
    user.value = null;
    if (next !== null) {
      void loadUser();
    }
  }

  return { token, user, ready, isAuthenticated, init, login, register, logout, clearSession, syncFromStorage };
});
