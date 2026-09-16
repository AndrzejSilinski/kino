/*
 * Bezpieczny dostęp do localStorage i sessionStorage (Etap 8, blok D).
 *
 * Przeglądarka potrafi odmówić dostępu do magazynu: tryb prywatny Safari, zablokowane
 * ciasteczka, przekroczony limit. Wtedy sam odczyt window.localStorage rzuca wyjątkiem.
 * Aplikacja ma działać dalej (bez zapamiętania kina czy koszyka po odświeżeniu),
 * a nie paść na białym ekranie — dlatego każde wywołanie jest w try/catch.
 */
export type StorageKind = 'local' | 'session';

export function safeStorage(kind: StorageKind): Storage | null {
  try {
    const storage = kind === 'local' ? window.localStorage : window.sessionStorage;
    const probe = '__cinema_probe__';
    storage.setItem(probe, probe);
    storage.removeItem(probe);
    return storage;
  } catch {
    return null;
  }
}

export function readItem(storage: Storage | null, key: string): string | null {
  try {
    return storage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

export function writeItem(storage: Storage | null, key: string, value: string): boolean {
  try {
    storage?.setItem(key, value);
    return storage !== null;
  } catch {
    return false;
  }
}

export function removeItem(storage: Storage | null, key: string): void {
  try {
    storage?.removeItem(key);
  } catch {
    // Magazyn niedostępny — nie ma czego usuwać.
  }
}
