import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { cartOf, mapSeat, snapshot } from './fixtures/seats';

const api = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: api }));

const { useSeatMapStore } = await import('@/stores/seatMap');
const { useCartStore } = await import('@/stores/cart');

describe('plan sali i koszyk wobec zdarzeń na żywo', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('zdarzenie nakłada stan absolutny i wersję; własna blokada nie staje się cudzą', async () => {
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' }), mapSeat({ position: { x: 2, y: 1 } }), mapSeat({ position: { x: 3, y: 1 }, status: 'held' })], 5));
    const store = useSeatMapStore();
    await store.load(334);

    store.applyChanges({ held: [891, 892], free: [893] }, 6);

    expect(store.seats.map((seat) => seat.status)).toEqual(['held_by_you', 'held', 'free']);
    expect(store.version).toBe(6);
  });

  it('migawka starsza niż stan ze zdarzeń nie cofa planu', async () => {
    api.seatMap.mockResolvedValueOnce(snapshot([mapSeat({ position: { x: 1, y: 1 } })], 5));
    const store = useSeatMapStore();
    await store.load(334);
    store.applyChanges({ sold: [891] }, 7);

    api.seatMap.mockResolvedValueOnce(snapshot([mapSeat({ position: { x: 1, y: 1 } })], 6));
    await store.load(334);

    expect(store.byId.get(891)?.status).toBe('sold');
    expect(store.version).toBe(7);
  });

  it('zdarzenie "wolne" dla własnego miejsca (wygasła blokada) każe pobrać koszyk od nowa', async () => {
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } })], 5));
    api.lock.mockResolvedValue(cartOf([891]));
    const cart = useCartStore();
    await cart.start(334);
    await cart.toggle(891);
    api.cart.mockResolvedValue(cartOf([]));

    cart.onSeatChanges({ held: [891] });
    expect(api.cart).not.toHaveBeenCalled();

    cart.onSeatChanges({ free: [891] });
    await vi.waitFor(() => expect(cart.ownSeatIds.size).toBe(0));
    expect(cart.notice?.tone).toBe('info');
  });
});
