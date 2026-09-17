/*
 * Powiadomienia push w TEJ przeglądarce (Etap 8, blok L).
 *
 * Trzy różne rzeczy, których nie wolno mylić:
 *   1. zgoda na koncie (push_enabled, blok I) — wspólna dla wszystkich urządzeń klienta,
 *   2. uprawnienie przeglądarki (Notification.permission) — zna je tylko to urządzenie,
 *   3. urządzenie zarejestrowane na serwerze (token FCM, blok K).
 * Push dostaje urządzenie, które ma wszystkie trzy. "Włącz w tej przeglądarce" (gest użytkownika —
 * przeglądarki blokują prośbę o uprawnienie bez kliknięcia) ustawia wszystkie trzy naraz.
 *
 * W localStorage zapamiętujemy {id, token} urządzenia: id do wyrejestrowania, token do wykrycia,
 * że FCM wydał nowy (wtedy rejestracja z replaces). Token FCM to identyfikator instalacji, nie hasło.
 * Wylogowanie: serwer usuwa urządzenie sam (ON DELETE CASCADE), a tu zapominamy zapis i token.
 */
import { ref } from 'vue';
import { defineStore } from 'pinia';
import { accountApi } from '@/api/client';
import { loadClientConfig, type FirebaseWebConfig } from '@/api/clientConfig';
import { isApiError } from '@/api/errors';
import { readItem, removeItem, safeStorage, writeItem } from '@/lib/storage';
import { browserWebPush, type WebPushClient } from '@/push/webPush';

export const PUSH_DEVICE_KEY = 'cinema.push.device';

export type BrowserPushStatus = 'checking' | 'unavailable' | 'unsupported' | 'denied' | 'off' | 'on';

interface StoredDevice {
  id: string;
  token: string;
}

let client: WebPushClient = browserWebPush;

/** Tylko dla testów: jsdom nie ma service workerów ani PushManagera. */
export function useWebPushClient(next: WebPushClient): void {
  client = next;
}

export const useWebPushStore = defineStore('webPush', () => {
  const storage = safeStorage('local');
  const status = ref<BrowserPushStatus>('checking');
  const busy = ref(false);
  const message = ref<string | null>(null);
  let config: FirebaseWebConfig | null = null;

  function stored(): StoredDevice | null {
    try {
      const value = JSON.parse(readItem(storage, PUSH_DEVICE_KEY) ?? 'null') as StoredDevice | null;
      return value && typeof value.id === 'string' && typeof value.token === 'string' ? value : null;
    } catch {
      return null;
    }
  }

  function remember(device: StoredDevice | null): void {
    if (device) {
      writeItem(storage, PUSH_DEVICE_KEY, JSON.stringify(device));
    } else {
      removeItem(storage, PUSH_DEVICE_KEY);
    }
  }

  /** Rejestracja tokenu; przy nowym tokenie FCM stary zastępowany jednym żądaniem (replaces). */
  async function register(token: string): Promise<void> {
    const previous = stored();
    const device = await accountApi.registerDevice(token, previous && previous.token !== token ? previous.token : null);
    remember({ id: device.id, token });
  }

  /** Stan dla tej przeglądarki; przy włączonym push odświeża token po cichu (FCM potrafi wydać nowy). */
  async function check(): Promise<void> {
    message.value = null;
    const loaded = await loadClientConfig().catch(() => null);
    config = loaded?.push.enabled && loaded.push.firebase ? loaded.push.firebase : null;
    if (!config) {
      status.value = 'unavailable';
      return;
    }
    if (!(await client.supported())) {
      status.value = 'unsupported';
      return;
    }
    const permission = client.permission();
    const device = stored();
    if (permission === 'denied') {
      status.value = 'denied';
    } else if (permission === 'granted' && device) {
      status.value = 'on';
      try {
        const token = await client.token(config);
        if (token !== device.token) {
          await register(token);
        }
      } catch {
        // Odświeżenie przy wejściu na ekran nie może psuć widoku — spróbujemy przy następnym razie.
      }
    } else {
      status.value = 'off';
    }
  }

  /** Wywoływać WYŁĄCZNIE z kliknięcia: prośba o uprawnienie bez gestu jest blokowana. */
  async function enable(): Promise<boolean> {
    if (!config || busy.value) {
      return false;
    }
    busy.value = true;
    message.value = null;
    try {
      const permission = await client.requestPermission();
      if (permission !== 'granted') {
        status.value = permission === 'denied' ? 'denied' : 'off';
        message.value = permission === 'denied' ? null : 'Przeglądarka nie dostała zgody na powiadomienia.';
        return false;
      }
      const token = await client.token(config);
      await accountApi.updateNotificationSettings({ push_enabled: true });
      await register(token);
      status.value = 'on';
      return true;
    } catch (error) {
      message.value = isApiError(error) ? error.message : 'Nie udało się włączyć powiadomień w tej przeglądarce. Spróbuj ponownie.';
      return false;
    } finally {
      busy.value = false;
    }
  }

  /** Wyłączenie w tej przeglądarce. Zgoda na koncie zostaje — dotyczy też innych urządzeń. */
  async function disable(): Promise<void> {
    if (busy.value) {
      return;
    }
    busy.value = true;
    message.value = null;
    const device = stored();
    try {
      if (device) {
        await accountApi.unregisterDevice(device.id).catch((error: unknown) => {
          // 404: urządzenie usunięte już na serwerze (wylogowanie w innej karcie, nieważny token).
          if (!isApiError(error) || error.status !== 404) {
            throw error;
          }
        });
      }
      remember(null);
      if (config) {
        await client.deleteToken(config).catch(() => {});
      }
      status.value = 'off';
    } catch (error) {
      message.value = isApiError(error) ? error.message : 'Nie udało się wyłączyć powiadomień. Spróbuj ponownie.';
    } finally {
      busy.value = false;
    }
  }

  /** Wylogowanie: serwer usunął urządzenie (CASCADE); ta przeglądarka przestaje odbierać push. */
  async function forget(): Promise<void> {
    if (stored() === null) {
      return;
    }
    remember(null);
    status.value = status.value === 'on' ? 'off' : status.value;
    const loaded = config ?? (await loadClientConfig().catch(() => null))?.push.firebase ?? null;
    if (loaded) {
      await client.deleteToken(loaded).catch(() => {});
    }
  }

  /**
   * Start aplikacji zalogowanego klienta: odświeżenie tokenu tylko tam, gdzie push jest już włączony
   * (zapis w localStorage) — pozostali nie pobierają kodu Firebase na każdej stronie.
   */
  async function refreshIfRegistered(): Promise<void> {
    if (stored() !== null) {
      await check();
    }
  }

  return { status, busy, message, check, enable, disable, forget, refreshIfRegistered };
});
