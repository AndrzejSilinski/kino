import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/api/errors';
import type { Cart } from '@/api/types';
import { presentSeat } from '@/lib/seatState';
import { cartOf, deferred, mapSeat, snapshot } from './fixtures/seats';

const api = vi.hoisted(() => ({ seatMap: vi.fn(), cart: vi.fn(), lock: vi.fn(), release: vi.fn(), releaseAll: vi.fn() }));
vi.mock('@/api/client', () => ({ seatsApi: api }));

const { useCartStore } = await import('@/stores/cart');
const { useSeatMapStore } = await import('@/stores/seatMap');

const A1 = 891;
const A2 = 892;
const A3 = 893;

async function started(seats = [mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 } }), mapSeat({ position: { x: 3, y: 1 } })], maxSeats = 10) {
  api.seatMap.mockResolvedValue(snapshot(seats));
  const cart = useCartStore();
  await cart.start(334, maxSeats);
  return { cart, seatMap: useSeatMapStore() };
}

const view = (cart: ReturnType<typeof useCartStore>, seatMap: ReturnType<typeof useSeatMapStore>, id: number) =>
  presentSeat(seatMap.byId.get(id)!, cart.ownSeatIds, cart.pending);

describe('koszyk: blokowanie bez optymizmu', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('start bez własnych blokad nie odpytuje koszyka (limit 30/min), z własnymi — odpytuje', async () => {
    await started();
    expect(api.cart).not.toHaveBeenCalled();

    setActivePinia(createPinia());
    api.cart.mockResolvedValue(cartOf([A2]));
    const { cart } = await started([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 2, y: 1 }, status: 'held_by_you' })]);
    expect(api.cart).toHaveBeenCalledTimes(1);
    expect([...cart.ownSeatIds]).toEqual([A2]);
  });

  it('kliknięcie: miejsce czeka na serwer (bez zmiany wyglądu), po 201 jest wybrane z sumą i timerem', async () => {
    const { cart, seatMap } = await started();
    const response = deferred<Cart>();
    api.lock.mockReturnValue(response.promise);

    const done = cart.toggle(A1);
    expect(view(cart, seatMap, A1)).toEqual({ view: 'free', busy: true, actionable: false });

    response.resolve(cartOf([A1], 600));
    await done;

    expect(api.lock).toHaveBeenCalledWith(334, A1);
    expect(view(cart, seatMap, A1)).toMatchObject({ view: 'selected', busy: false });
    expect(cart.cart?.total.formatted).toBe('17,60 zł');
    expect(cart.deadline?.seconds).toBe(600);
    expect(seatMap.byId.get(A1)?.status).toBe('held_by_you');
  });

  it('zdarzenie "held" przychodzi PRZED odpowiedzią 201: miejsce nie jest pokazane jako cudze', async () => {
    const { cart, seatMap } = await started();
    const response = deferred<Cart>();
    api.lock.mockReturnValue(response.promise);

    const done = cart.toggle(A1);
    seatMap.setStatus([A1], 'held');
    expect(view(cart, seatMap, A1).view).toBe('free');

    response.resolve(cartOf([A1]));
    await done;
    expect(view(cart, seatMap, A1).view).toBe('selected');
  });

  it('ta sama milisekunda: 409 SEATS_UNAVAILABLE oznacza miejsce jako zajęte, pokazuje komunikat i odświeża plan', async () => {
    const { cart, seatMap } = await started();
    api.lock.mockRejectedValue(new ApiError({ status: 409, code: 'SEATS_UNAVAILABLE', message: 'Miejsca A1 zostały właśnie zajęte przez kogoś innego.', context: { seat_ids: [A1], seats: ['A1'] } }));
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 }, status: 'held' })], 6));

    await cart.toggle(A1);

    expect(view(cart, seatMap, A1)).toMatchObject({ view: 'held', actionable: false });
    expect(cart.notice).toEqual({ tone: 'error', text: 'Miejsca A1 zostały właśnie zajęte przez kogoś innego.' });
    expect(cart.ownSeatIds.size).toBe(0);
    await vi.waitFor(() => expect(api.seatMap).toHaveBeenCalledTimes(2));
  });

  it('podwójne kliknięcie w trakcie żądania wysyła jedno żądanie', async () => {
    const { cart } = await started();
    const response = deferred<Cart>();
    api.lock.mockReturnValue(response.promise);

    const first = cart.toggle(A1);
    expect(cart.toggle(A1)).toBeNull();
    response.resolve(cartOf([A1]));
    await first;

    expect(api.lock).toHaveBeenCalledTimes(1);
  });

  it('kliknięcia idą szeregowo: drugie żądanie wychodzi po odpowiedzi na pierwsze', async () => {
    const { cart } = await started();
    const first = deferred<Cart>();
    api.lock.mockReturnValueOnce(first.promise).mockResolvedValueOnce(cartOf([A1, A2]));

    const a = cart.toggle(A1);
    const b = cart.toggle(A2);
    await Promise.resolve();
    expect(api.lock).toHaveBeenCalledTimes(1);

    first.resolve(cartOf([A1]));
    await Promise.all([a, b]);
    expect(api.lock).toHaveBeenNthCalledWith(2, 334, A2);
    expect([...cart.ownSeatIds].sort()).toEqual([A1, A2]);
  });

  it('odkliknięcie zwalnia miejsce po odpowiedzi serwera i aktualizuje koszyk', async () => {
    const { cart, seatMap } = await started();
    api.lock.mockResolvedValue(cartOf([A1, A2]));
    await cart.toggle(A1);
    api.release.mockResolvedValue(cartOf([A2]));

    await cart.toggle(A1);

    expect(api.release).toHaveBeenCalledWith(334, A1);
    expect(view(cart, seatMap, A1).view).toBe('free');
    expect(cart.cart?.seats_count).toBe(1);
  });

  it('limit miejsc: kolejne kliknięcie nie idzie do serwera, klient dostaje informację', async () => {
    const { cart } = await started(undefined, 1);
    api.lock.mockResolvedValue(cartOf([A1]));
    await cart.toggle(A1);

    expect(cart.toggle(A2)).toBeNull();
    expect(api.lock).toHaveBeenCalledTimes(1);
    expect(cart.notice?.text).toBe('W jednym zamówieniu można wybrać najwyżej 1 miejsc.');
  });

  it('429: kolejne kliknięcia są wstrzymane do upływu Retry-After', async () => {
    const { cart } = await started();
    api.lock.mockRejectedValue(new ApiError({ status: 429, code: 'TOO_MANY_REQUESTS', message: 'x', retryAfterSeconds: 30 }));
    await cart.toggle(A1);

    expect(cart.toggle(A2)).toBeNull();
    expect(api.lock).toHaveBeenCalledTimes(1);
    expect(cart.notice?.text).toContain('Odczekaj');
  });

  it('wygaśnięcie najwcześniejszej blokady: pobiera resztę koszyka i plan zamiast czyścić wybór', async () => {
    const { cart } = await started();
    api.lock.mockResolvedValueOnce(cartOf([A1])).mockResolvedValueOnce(cartOf([A1, A3]));
    await cart.toggle(A1);
    await cart.toggle(A3);
    api.cart.mockResolvedValue(cartOf([A3], 240));
    api.seatMap.mockResolvedValue(snapshot([mapSeat({ position: { x: 1, y: 1 } }), mapSeat({ position: { x: 3, y: 1 }, status: 'held_by_you' })], 9));

    await cart.onExpired();

    expect([...cart.ownSeatIds]).toEqual([A3]);
    expect(cart.deadline?.seconds).toBe(240);
    expect(cart.notice?.tone).toBe('info');
  });

  it('"Wyczyść wybór" zwalnia cały koszyk i oznacza miejsca jako wolne', async () => {
    const { cart, seatMap } = await started();
    api.lock.mockResolvedValueOnce(cartOf([A1])).mockResolvedValueOnce(cartOf([A1, A2]));
    await cart.toggle(A1);
    await cart.toggle(A2);
    api.releaseAll.mockResolvedValue(cartOf([]));

    await cart.clear();

    expect(cart.ownSeatIds.size).toBe(0);
    expect(cart.deadline).toBeNull();
    expect([seatMap.byId.get(A1)?.status, seatMap.byId.get(A2)?.status]).toEqual(['free', 'free']);
  });
});
