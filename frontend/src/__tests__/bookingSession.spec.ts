import { describe, expect, it } from 'vitest';
import { BOOKING_SESSION_KEY, createBookingSessionStore } from '@/api/bookingSession';

function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() { return data.size; },
    clear: () => data.clear(),
    getItem: (key) => data.get(key) ?? null,
    key: (index) => [...data.keys()][index] ?? null,
    removeItem: (key) => { data.delete(key); },
    setItem: (key, value) => { data.set(key, value); },
  };
}

describe('sesja zakupowa w przeglądarce', () => {
  it('zapamiętuje wyłącznie identyfikator w formacie serwera (32 znaki alfanumeryczne)', () => {
    const storage = memoryStorage();
    const store = createBookingSessionStore(storage);

    store.set('za-krotki');
    expect(store.get()).toBeNull();

    store.set('x'.repeat(32));
    expect(store.get()).toBe('x'.repeat(32));
    expect(storage.getItem(BOOKING_SESSION_KEY)).toBe('x'.repeat(32));
  });

  it('bez dostępnego magazynu trzyma identyfikator w pamięci karty', () => {
    const store = createBookingSessionStore(null);

    store.set('Q'.repeat(32));
    expect(store.get()).toBe('Q'.repeat(32));

    store.clear();
    expect(store.get()).toBeNull();
  });

  it('magazyn rzucający wyjątkiem nie psuje aplikacji', () => {
    const broken = memoryStorage();
    broken.setItem = () => { throw new DOMException('QuotaExceededError'); };
    broken.getItem = () => { throw new DOMException('SecurityError'); };
    const store = createBookingSessionStore(broken);

    expect(() => store.set('Z'.repeat(32))).not.toThrow();
    expect(store.get()).toBe('Z'.repeat(32));
  });
});
