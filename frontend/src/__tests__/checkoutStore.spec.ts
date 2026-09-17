import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/api/errors';
import { booking, checkoutResult, REFERENCE } from './fixtures/checkout';
import { deferred } from './fixtures/seats';

const api = vi.hoisted(() => ({ checkout: vi.fn(), abandonPayment: vi.fn() }));
vi.mock('@/api/client', () => ({ bookingsApi: api }));

const { useCheckoutStore, problemFrom } = await import('@/stores/checkout');

const error = (status: number, code: string, context: Record<string, unknown> = {}) =>
  new ApiError({ status, code, message: `komunikat ${code}`, context });

describe('checkout: store', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('udany checkout: wynik w pamięci, termin płatności z serwera, etap "ready"', async () => {
    api.checkout.mockResolvedValue({ created: true, result: checkoutResult(598) });
    const checkout = useCheckoutStore();

    expect(await checkout.start(334)).toBe(true);

    expect(api.checkout).toHaveBeenCalledWith(334);
    expect(checkout.phase).toBe('ready');
    expect(checkout.result?.booking.reference).toBe(REFERENCE);
    expect(checkout.deadline?.seconds).toBe(598);
  });

  it('podwójne kliknięcie "Przejdź do płatności" wysyła jedno żądanie', async () => {
    const response = deferred<{ created: boolean; result: ReturnType<typeof checkoutResult> }>();
    api.checkout.mockReturnValue(response.promise);
    const checkout = useCheckoutStore();

    const first = checkout.start(334);
    const second = checkout.start(334);
    expect(checkout.phase).toBe('starting');
    response.resolve({ created: true, result: checkoutResult() });

    expect(await Promise.all([first, second])).toEqual([true, true]);
    expect(api.checkout).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['EMPTY_CART', 422, {}, false, null, null],
    ['BOOKING_ALREADY_PENDING', 409, { booking_reference: REFERENCE }, false, REFERENCE, null],
    ['BOOKING_NOT_PAYABLE', 409, { booking_status: 'paid' }, false, null, 'paid'],
    ['PAYMENT_PROVIDER_UNAVAILABLE', 503, {}, true, null, null],
    ['SCREENING_NOT_BOOKABLE', 409, { screening_id: 334 }, false, null, null],
  ])('błąd %s rozpoznany po kodzie (ponowienie: %s)', async (code, status, context, retryable, reference, bookingStatus) => {
    api.checkout.mockRejectedValue(error(status, code, context));
    const checkout = useCheckoutStore();

    expect(await checkout.start(334)).toBe(false);

    expect(checkout.phase).toBe('idle');
    expect(checkout.problem).toEqual({ code, message: `komunikat ${code}`, retryable, reference, bookingStatus });
  });

  it('brak sieci pozwala ponowić, a błąd spoza API dostaje ogólny komunikat', () => {
    expect(problemFrom(new ApiError({ status: 0, code: 'NETWORK_ERROR', message: 'x' })).retryable).toBe(true);
    expect(problemFrom(new Error('nie z API'))).toMatchObject({ code: 'UNKNOWN', retryable: true });
  });

  it('rezygnacja czyści płatność; numer rezerwacji może przyjść z koszyka', async () => {
    api.checkout.mockResolvedValue({ created: true, result: checkoutResult() });
    api.abandonPayment.mockResolvedValue(booking({ status: 'cancelled', status_label: 'Anulowana' }));
    const checkout = useCheckoutStore();
    await checkout.start(334);

    expect((await checkout.abandon())?.status).toBe('cancelled');
    expect(api.abandonPayment).toHaveBeenCalledWith(REFERENCE);
    expect(checkout.result).toBeNull();
    expect(checkout.phase).toBe('idle');

    await checkout.abandon('01INNAREZERWACJA0000000000');
    expect(api.abandonPayment).toHaveBeenLastCalledWith('01INNAREZERWACJA0000000000');
  });

  it('nieudana rezygnacja (opłacona w międzyczasie) zostawia płatność i pokazuje problem', async () => {
    api.checkout.mockResolvedValue({ created: true, result: checkoutResult() });
    api.abandonPayment.mockRejectedValue(error(409, 'BOOKING_NOT_PAYABLE', { booking_status: 'paid' }));
    const checkout = useCheckoutStore();
    await checkout.start(334);

    expect(await checkout.abandon()).toBeNull();

    expect(checkout.phase).toBe('ready');
    expect(checkout.result).not.toBeNull();
    expect(checkout.problem).toMatchObject({ code: 'BOOKING_NOT_PAYABLE', bookingStatus: 'paid' });
  });

  it('koniec okna płatności blokuje płacenie; zmiana seansu zeruje stan', async () => {
    api.checkout.mockResolvedValue({ created: true, result: checkoutResult() });
    const checkout = useCheckoutStore();
    await checkout.start(334);

    checkout.onPaymentExpired();
    expect(checkout.phase).toBe('expired');
    expect(checkout.deadline).toBeNull();

    api.checkout.mockReturnValue(new Promise(() => {}));
    void checkout.start(335);
    expect(checkout.result).toBeNull();
    expect(checkout.screeningId).toBe(335);
  });
});
