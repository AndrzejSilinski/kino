/*
 * Plan sali seansu (Etap 8, blok F): migawka z GET seat-map i statusy miejsc.
 *
 * Statusy są stanem ABSOLUTNYM z serwera (migawka, a w bloku G także zdarzenia WebSocket).
 * Store nie wie, które blokady są "moje" poza tym, co powie serwer w migawce (held_by_you) —
 * własne blokady trzyma store koszyka z odpowiedzi seat-locks.
 * seat_state_version zapamiętujemy już teraz: blok G porówna z nią wersje zdarzeń.
 */
import { computed, ref, shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { seatsApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { MapSeat, ScreeningDetails, SeatStatus } from '@/api/types';
import type { SeatChanges } from '@/realtime/seatSync';
import { messageFor } from '@/messages';

export const useSeatMapStore = defineStore('seatMap', () => {
  const screeningId = ref<number | null>(null);
  const screening = shallowRef<ScreeningDetails | null>(null);
  const seats = shallowRef<MapSeat[]>([]);
  const version = ref(0);
  const loading = ref(false);
  const errorMessage = ref<string | null>(null);
  const notFound = ref(false);
  let sequence = 0;

  const byId = computed(() => new Map(seats.value.map((seat) => [seat.id, seat])));

  /** Pobiera migawkę; wynik starszego z nakładających się odczytów jest ignorowany. */
  async function load(id: number): Promise<boolean> {
    const current = ++sequence;
    if (screeningId.value !== id) {
      screeningId.value = id;
      screening.value = null;
      seats.value = [];
      version.value = 0;
    }
    loading.value = true;
    errorMessage.value = null;
    notFound.value = false;
    try {
      const snapshot = await seatsApi.seatMap(id);
      if (current !== sequence) {
        return false;
      }
      screening.value = snapshot.screening;
      // Etap 8, blok G: migawka starsza niż stan ze zdarzeń WebSocket cofnęłaby plan
      // (żądanie wyszło przed zmianą, odpowiedź przyszła po zdarzeniu). Wtedy zostawiamy statusy.
      if (snapshot.seat_state_version >= version.value || seats.value.length === 0) {
        seats.value = snapshot.seats;
        version.value = snapshot.seat_state_version;
      }
      return true;
    } catch (error) {
      if (current === sequence) {
        errorMessage.value = messageFor(error);
        notFound.value = isApiError(error) && error.status === 404;
      }
      return false;
    } finally {
      if (current === sequence) {
        loading.value = false;
      }
    }
  }

  /** Nadaje status wskazanym miejscom (nowa tablica — shallowRef zauważa zmianę). */
  function setStatus(ids: Iterable<number>, status: SeatStatus): void {
    const wanted = new Set(ids);
    if (wanted.size === 0) {
      return;
    }
    seats.value = seats.value.map((seat) => (wanted.has(seat.id) && seat.status !== status ? { ...seat, status } : seat));
  }

  /**
   * 409 SEATS_UNAVAILABLE niesie tylko identyfikatory, bez statusu (zablokowane czy sprzedane?).
   * Oznaczamy je jako zajęte przez innych; dokładny stan przyniesie migawka albo zdarzenie.
   */
  function markTaken(ids: Iterable<number>): void {
    const taken = new Set(ids);
    seats.value = seats.value.map((seat) => (taken.has(seat.id) && (seat.status === 'free' || seat.status === 'held_by_you') ? { ...seat, status: 'held' } : seat));
  }

  /**
   * Zdarzenie seats.changed: stan absolutny pogrupowany po statusie (free / held / sold).
   * Kolejność i luki w wersjach pilnuje seatSync — tu tylko nakładamy i przesuwamy wersję.
   */
  function applyChanges(changes: SeatChanges, newVersion: number): void {
    const next = new Map<number, SeatStatus>();
    for (const [status, ids] of Object.entries(changes) as [SeatStatus, number[] | undefined][]) {
      ids?.forEach((id) => next.set(id, status));
    }
    seats.value = seats.value.map((seat) => {
      const status = next.get(seat.id);
      // "held_by_you" zostaje przy "held": zdarzenie nie mówi, czyja blokada (o tym decyduje koszyk).
      if (status === undefined || status === seat.status || (status === 'held' && seat.status === 'held_by_you')) {
        return seat;
      }
      return { ...seat, status };
    });
    version.value = newVersion;
  }

  return { screeningId, screening, seats, version, loading, errorMessage, notFound, byId, load, setStatus, markTaken, applyChanges };
});
