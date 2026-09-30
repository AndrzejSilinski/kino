/*
 * Trasy aplikacji z widokami zastąpionymi pustym komponentem (Etap 10, blok A2, pułapka EN).
 *
 * Testy typu „komponent przechodzi na trasę X” sprawdzają, DOKĄD prowadzi nawigacja, a nie
 * ładowanie widoku docelowego. Prawdziwe trasy ładują widoki leniwie (import()), a vi.waitFor
 * czeka domyślnie 1 s — na obciążonej maszynie pierwsze ładowanie widoku trwało dłużej i test
 * padał bez żadnej zmiany w kodzie. Ścieżki, nazwy, meta, beforeEnter i trasy potomne zostają
 * prawdziwe; zamieniamy tylko komponent.
 *
 * Funkcja przyjmuje trasy jako argument zamiast importować '@/router': test ładuje router
 * dopiero po vi.mock (await import), a ten plik nie może tego obejść.
 */
import type { RouteRecordRaw } from 'vue-router';

const PustyWidok = { name: 'PustyWidokTestowy', render: () => null };

export function bezWidokow(records: readonly RouteRecordRaw[]): RouteRecordRaw[] {
  return records.map((record) => ({
    ...record,
    ...('component' in record && record.component ? { component: PustyWidok } : {}),
    ...(record.children ? { children: bezWidokow(record.children) } : {}),
  }) as RouteRecordRaw);
}
