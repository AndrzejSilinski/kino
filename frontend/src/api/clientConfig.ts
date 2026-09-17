/*
 * Konfiguracja klienta pobierana w czasie działania: GET /api/v1/client-config (Etap 8, blok C).
 *
 * Dlaczego nie zmienne VITE_*: wszystko z VITE_ zostaje wpisane w zbudowany plik JS,
 * więc jeden build nie nadałby się do innego środowiska (Etap 10: jeden obraz dla dev i prod).
 * Endpoint zwraca wyłącznie wartości jawne z natury (klucz publiczny Reverba, limity koszyka).
 *
 * Pełny klient HTTP (koperta data, kody błędów, 401, 429) powstaje w bloku D.
 */
export interface FirebaseWebConfig {
  api_key: string;
  app_id: string;
  project_id: string;
  messaging_sender_id: string;
  vapid_public_key: string;
}

export interface ClientConfig {
  api_version: string;
  realtime: {
    broadcaster: 'reverb';
    key: string;
    path: string;
  };
  booking: {
    seat_lock_ttl_seconds: number;
    max_seats_per_session: number;
    payment_window_seconds: number;
  };
  /** Web Push (blok L). firebase tylko przy enabled — konfiguracja aplikacji web i klucz VAPID, wartości jawne. */
  push: {
    enabled: boolean;
    firebase?: FirebaseWebConfig;
  };
}

export async function fetchClientConfig(fetchImpl: typeof fetch = fetch): Promise<ClientConfig> {
  const response = await fetchImpl('/api/v1/client-config', {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Konfiguracja klienta niedostępna (HTTP ${response.status}).`);
  }

  const body = (await response.json()) as { data?: ClientConfig };

  if (!body.data || typeof body.data.realtime?.key !== 'string') {
    throw new Error('Nieprawidłowa odpowiedź konfiguracji klienta.');
  }

  return body.data;
}

let cached: Promise<ClientConfig> | null = null;

/** Jedna konfiguracja na uruchomienie aplikacji; nieudane pobranie można ponowić. */
export function loadClientConfig(): Promise<ClientConfig> {
  cached ??= fetchClientConfig().catch((error: unknown) => {
    cached = null;
    throw error;
  });
  return cached;
}
