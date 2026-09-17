/*
 * Koszyk sesji zakupowej na seansie (Etap 8, blok F): blokowanie miejsc BEZ OPTYMIZMU.
 *
 * Przepływ kliknięcia (wymóg 3.2):
 *   1. miejsce trafia do "pending" — wygląda jak przed kliknięciem, ma wskaźnik i jest zablokowane,
 *   2. żądanie idzie do kolejki szeregowej (jedno naraz, odpowiedzi w kolejności kliknięć),
 *   3. 201 -> koszyk z odpowiedzi jest źródłem prawdy o własnych blokadach, sumie i czasie,
 *      409 -> miejsce oznaczone jako zajęte, komunikat serwera, świeża migawka planu (bez limitu żądań).
 *
 * "Ta sama milisekunda": dwóch klientów klika to samo miejsce, o wyniku rozstrzyga indeks UNIQUE
 * w PostgreSQL (Etap 2). Jeden dostaje 201 i widzi "wybrane", drugi 409 i widzi "zajęte" z komunikatem.
 *
 * Timer: koszyk wygasa razem z NAJWCZEŚNIEJSZĄ blokadą, a każda blokada ma własny termin (serwer
 * nie wydłuża starszych). Po zerze wraca do puli tylko to jedno miejsce — dlatego pobieramy koszyk
 * od nowa zamiast czyścić cały wybór.
 *
 * Rozpoczęta płatność (blok H2): koszyk z pending_booking oznacza, że blokady należą do rezerwacji.
 * Serwer ich wtedy nie zwalnia, więc plan sali jest zamrożony — zmiana wyboru wymaga rezygnacji
 * z płatności (DELETE /bookings/{ref}/payment) albo jej dokończenia.
 */
import { computed, ref, shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { seatsApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Cart } from '@/api/types';
import type { Deadline } from '@/composables/useCountdown';
import type { SeatChanges } from '@/realtime/seatSync';
import { presentSeat } from '@/lib/seatState';
import { createSerialQueue } from '@/lib/serialQueue';
import { messageFor } from '@/messages';
import { useSeatMapStore } from './seatMap';

export const DEFAULT_MAX_SEATS = 10;

export interface CartNotice {
  tone: 'error' | 'info';
  text: string;
}

export const useCartStore = defineStore('cart', () => {
  const seatMap = useSeatMapStore();
  const queue = createSerialQueue();

  const screeningId = ref<number | null>(null);
  const cart = shallowRef<Cart | null>(null);
  const deadline = shallowRef<Deadline | null>(null);
  const pending = shallowRef<ReadonlySet<number>>(new Set());
  const notice = ref<CartNotice | null>(null);
  const closed = ref(false);
  const maxSeats = ref(DEFAULT_MAX_SEATS);
  const rateLimitedUntil = ref(0);

  const ownSeatIds = computed<ReadonlySet<number>>(() => new Set(cart.value?.seats.map((seat) => seat.seat_id) ?? []));
  const seatsCount = computed(() => cart.value?.seats_count ?? 0);
  const pendingBooking = computed(() => cart.value?.pending_booking ?? null);

  function setPending(seatId: number, on: boolean): void {
    const next = new Set(pending.value);
    if (on) {
      next.add(seatId);
    } else {
      next.delete(seatId);
    }
    pending.value = next;
  }

  function applyCart(next: Cart, released: number | null = null): void {
    const before = ownSeatIds.value;
    cart.value = next;
    deadline.value = next.expires_in_seconds !== null ? { seconds: next.expires_in_seconds, receivedAt: performance.now() } : null;

    const now = ownSeatIds.value;
    seatMap.setStatus(now, 'held_by_you');
    if (released !== null && !now.has(released)) {
      seatMap.setStatus([released], 'free');
    }
    // Własna blokada zniknęła bez odkliknięcia (wygasła albo sweep): dokładny stan tylko z migawki.
    const lost = [...before].filter((id) => !now.has(id) && id !== released);
    if (lost.length > 0 && screeningId.value !== null) {
      void seatMap.load(screeningId.value);
    }
  }

  function handleError(error: unknown, seatId: number): void {
    if (!isApiError(error)) {
      notice.value = { tone: 'error', text: messageFor(error) };
      return;
    }
    switch (error.code) {
      case 'SEATS_UNAVAILABLE': {
        const ids = Array.isArray(error.context.seat_ids) ? (error.context.seat_ids as number[]) : [seatId];
        seatMap.markTaken(ids.length > 0 ? ids : [seatId]);
        notice.value = { tone: 'error', text: error.message };
        if (screeningId.value !== null) {
          void seatMap.load(screeningId.value);
        }
        break;
      }
      case 'SCREENING_NOT_BOOKABLE':
        closed.value = true;
        notice.value = { tone: 'error', text: error.message };
        break;
      case 'TOO_MANY_REQUESTS':
        rateLimitedUntil.value = performance.now() + (error.retryAfterSeconds ?? 60) * 1000;
        notice.value = { tone: 'error', text: messageFor(error) };
        break;
      default:
        notice.value = { tone: 'error', text: messageFor(error) };
    }
  }

  async function refreshCart(): Promise<void> {
    if (screeningId.value === null) {
      return;
    }
    try {
      applyCart(await seatsApi.cart(screeningId.value));
    } catch (error) {
      notice.value = { tone: 'error', text: messageFor(error) };
    }
  }

  /** Wejście na plan sali: migawka, a koszyk z serwera tylko wtedy, gdy migawka pokazuje własne blokady. */
  async function start(id: number, limit = DEFAULT_MAX_SEATS): Promise<void> {
    if (screeningId.value !== id) {
      screeningId.value = id;
      cart.value = null;
      deadline.value = null;
      pending.value = new Set();
      closed.value = false;
      notice.value = null;
    }
    maxSeats.value = limit;
    const loaded = await seatMap.load(id);
    if (!loaded) {
      return;
    }
    closed.value = seatMap.screening?.is_bookable === false;
    if (seatMap.seats.some((seat) => seat.status === 'held_by_you')) {
      await refreshCart();
    } else {
      // Migawka bez własnych blokad = serwer nie ma naszego koszyka (np. płatność zakończona w bloku H3,
      // blokady zamienione w bilety). Stary koszyk w pamięci pokazałby zamrożony plan i nieaktualne miejsca.
      cart.value = null;
      deadline.value = null;
    }
  }

  function toggle(seatId: number): Promise<void> | null {
    const seat = seatMap.byId.get(seatId);
    const id = screeningId.value;
    if (!seat || id === null || closed.value) {
      return null;
    }
    if (pendingBooking.value !== null) {
      notice.value = { tone: 'info', text: 'Masz rozpoczętą płatność za wybrane miejsca. Dokończ ją albo z niej zrezygnuj, żeby zmienić wybór.' };
      return null;
    }
    const presentation = presentSeat(seat, ownSeatIds.value, pending.value);
    if (!presentation.actionable) {
      return null;
    }
    if (performance.now() < rateLimitedUntil.value) {
      notice.value = { tone: 'error', text: 'Zbyt wiele prób w krótkim czasie. Odczekaj chwilę i spróbuj ponownie.' };
      return null;
    }

    if (presentation.view === 'selected') {
      setPending(seatId, true);
      return queue.push(async () => {
        try {
          applyCart(await seatsApi.release(id, seatId), seatId);
        } catch (error) {
          handleError(error, seatId);
        } finally {
          setPending(seatId, false);
        }
      });
    }

    const reserving = [...pending.value].filter((pendingId) => !ownSeatIds.value.has(pendingId)).length;
    if (ownSeatIds.value.size + reserving >= maxSeats.value) {
      notice.value = { tone: 'info', text: `W jednym zamówieniu można wybrać najwyżej ${maxSeats.value} miejsc.` };
      return null;
    }

    notice.value = null;
    setPending(seatId, true);
    return queue.push(async () => {
      try {
        applyCart(await seatsApi.lock(id, seatId));
      } catch (error) {
        handleError(error, seatId);
      } finally {
        setPending(seatId, false);
      }
    });
  }

  function clear(): Promise<void> | null {
    const id = screeningId.value;
    if (id === null || ownSeatIds.value.size === 0 || pendingBooking.value !== null) {
      return null;
    }
    const released = [...ownSeatIds.value];
    return queue.push(async () => {
      try {
        applyCart(await seatsApi.releaseAll(id));
        seatMap.setStatus(released.filter((seatId) => !ownSeatIds.value.has(seatId)), 'free');
      } catch (error) {
        handleError(error, released[0] ?? 0);
      }
    });
  }

  /**
   * 409 BOOKING_ALREADY_PENDING: do rozpoczętej płatności dobrano miejsca (np. w zduplikowanej karcie,
   * która dzieli sesję zakupową). "Zwolnij wszystko" oddaje tylko te dobrane — blokady wpięte
   * w rezerwację serwer zostawia — więc po nim checkout znów zwraca rozpoczętą płatność.
   */
  async function releaseExtraSeats(): Promise<boolean> {
    const id = screeningId.value;
    if (id === null) {
      return false;
    }
    try {
      applyCart(await seatsApi.releaseAll(id));
      return true;
    } catch (error) {
      notice.value = { tone: 'error', text: messageFor(error) };
      return false;
    }
  }

  /** Po rezygnacji z płatności: miejsca wróciły do puli — świeży koszyk i plan z serwera. */
  async function afterPaymentAbandoned(): Promise<void> {
    notice.value = { tone: 'info', text: 'Zrezygnowano z płatności. Miejsca wróciły do puli — możesz wybrać je ponownie.' };
    // Koszyk bez dawnych miejsc: applyCart sam dociągnie migawkę planu (utracone własne blokady).
    await refreshCart();
  }

  /** Najwcześniejsza blokada wygasła: serwer zwolnił to miejsce — pobieramy resztę koszyka i plan. */
  async function onExpired(): Promise<void> {
    notice.value = { tone: 'info', text: 'Czas rezerwacji części miejsc minął — wróciły do puli. Sprawdź wybór.' };
    await refreshCart();
    if (screeningId.value !== null) {
      await seatMap.load(screeningId.value);
    }
  }

  /** Powrót karty z tła: licznik mógł stanąć (uśpienie), a plan się zmienić. */
  async function resync(): Promise<void> {
    if (screeningId.value === null) {
      return;
    }
    const hadCart = ownSeatIds.value.size > 0;
    await seatMap.load(screeningId.value);
    if (hadCart || seatMap.seats.some((seat) => seat.status === 'held_by_you')) {
      await refreshCart();
    }
  }

  /**
   * Zdarzenie WebSocket (blok G) mówi, że nasze miejsce jest wolne albo sprzedane, a nie odklikaliśmy go:
   * blokada wygasła albo zabrało ją anulowanie seansu. Źródłem prawdy o koszyku jest serwer — pobieramy go.
   * "held" dla naszego miejsca to nasza blokada (zdarzenie nie mówi, czyja), więc go nie ruszamy.
   */
  function onSeatChanges(changes: SeatChanges): void {
    const lost = [...(changes.free ?? []), ...(changes.sold ?? [])]
      .some((id) => ownSeatIds.value.has(id) && !pending.value.has(id));
    if (lost) {
      notice.value = { tone: 'info', text: 'Jedno z wybranych miejsc wróciło do puli albo zostało sprzedane. Sprawdź wybór.' };
      void refreshCart();
    }
  }

  function dismissNotice(): void {
    notice.value = null;
  }

  return {
    screeningId, cart, deadline, pending, notice, closed, maxSeats, ownSeatIds, seatsCount, pendingBooking,
    start, toggle, clear, refreshCart, onExpired, resync, dismissNotice, onSeatChanges, releaseExtraSeats, afterPaymentAbandoned,
  };
});
