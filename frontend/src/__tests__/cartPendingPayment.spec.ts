import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { pendingCart, REFERENCE } from './fixtures/checkout';
import { cartOf, mapSeat, snapshot } from './fixtures/seats';

const api = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: api }));

const { useCartStore } = await import('@/stores/cart');
const { useSeatMapStore } = await import('@/stores/seatMap');

const A1 = 891;
const A2 = 892;

/** Plan po checkoucie: A1 w rezerwacji (held_by_you), A2 wolne. */
async function startedWithPendingPayment() {
  api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held_by_you' }), mapSeat({ position: { x: 2, y: 1 } })]));
  api.cart.mockResolvedValue(pendingCart([A1]));
  const cart = useCartStore();
  await cart.start(334);
  return { cart, seatMap: useSeatMapStore() };
}

describe('koszyk z rozpoczętą płatnością (blok H2)', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('pending_booking z serwera zamraża plan: kliknięcie nie wysyła żądania, tylko wyjaśnia dlaczego', async () => {
    const { cart } = await startedWithPendingPayment();

    expect(cart.pendingBooking?.reference).toBe(REFERENCE);
    expect(cart.toggle(A2)).toBeNull();
    expect(cart.toggle(A1)).toBeNull();
    expect(cart.clear()).toBeNull();

    expect(api.lock).not.toHaveBeenCalled();
    expect(api.release).not.toHaveBeenCalled();
    expect(api.releaseAll).not.toHaveBeenCalled();
    expect(cart.notice).toMatchObject({ tone: 'info' });
    expect(cart.notice?.text).toContain('rozpoczętą płatność');
  });

  it('"zwolnij dobrane miejsca": serwer oddaje tylko miejsce spoza rezerwacji', async () => {
    const { cart, seatMap } = await startedWithPendingPayment();
    api.releaseAll.mockResolvedValue(pendingCart([A1]));

    expect(await cart.releaseExtraSeats()).toBe(true);

    expect(api.releaseAll).toHaveBeenCalledWith(334);
    expect([...cart.ownSeatIds]).toEqual([A1]);
    expect(seatMap.byId.get(A1)?.status).toBe('held_by_you');
  });

  it('powrót na plan po opłaceniu: migawka bez własnych blokad czyści koszyk z pamięci (bez zamrożenia)', async () => {
    const { cart } = await startedWithPendingPayment();
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'sold' }), mapSeat({ position: { x: 2, y: 1 } })], 9));

    await cart.start(334);

    expect(cart.cart).toBeNull();
    expect(cart.pendingBooking).toBeNull();
    expect(cart.deadline).toBeNull();
    expect(api.cart).toHaveBeenCalledTimes(1);
  });

  it('po rezygnacji z płatności: pusty koszyk, odmrożony plan i świeża migawka', async () => {
    const { cart, seatMap } = await startedWithPendingPayment();
    api.cart.mockResolvedValue(cartOf([]));
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 } })], 7));

    await cart.afterPaymentAbandoned();
    await vi.waitFor(() => expect(seatMap.version).toBe(7));

    expect(cart.pendingBooking).toBeNull();
    expect(cart.seatsCount).toBe(0);
    expect(seatMap.byId.get(A1)?.status).toBe('free');
    expect(cart.notice?.text).toContain('Zrezygnowano z płatności');
  });
});
