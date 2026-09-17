/*
 * Ścieżka API z pełnego adresu podanego przez serwer (blok H4).
 *
 * qr_url przychodzi jako pełny adres zbudowany z APP_URL (np. http://localhost:8080/api/v1/bookings/…/qr).
 * Klient HTTP dokleja bazę /api/v1 i token bearer, więc potrzebuje samej ścieżki. Adres z INNEGO originu
 * odrzucamy: wysłanie tam tokenu oddałoby dostęp do konta obcemu serwerowi (np. po błędnej konfiguracji
 * APP_URL albo podmienionej odpowiedzi).
 */
export const API_BASE = '/api/v1';

export function apiPathFromUrl(url: string, origin: string = window.location.origin): string | null {
  let parsed: URL;
  try {
    parsed = new URL(url, origin);
  } catch {
    return null;
  }
  if (parsed.origin !== origin || !parsed.pathname.startsWith(`${API_BASE}/`)) {
    return null;
  }
  return `${parsed.pathname.slice(API_BASE.length)}${parsed.search}`;
}
