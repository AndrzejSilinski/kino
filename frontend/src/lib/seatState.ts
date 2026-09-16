/*
 * Co widzi klient na danym miejscu (Etap 8, blok F) — czysta logika bez Vue.
 *
 * DWA ŹRÓDŁA PRAWDY, CELOWO ROZDZIELONE:
 *   - status z planu sali / zdarzeń WebSocket: stan absolutny, ale bez informacji CZYJA jest blokada
 *     (decyzja 26) — "held" może być moją blokadą, której odpowiedź HTTP jeszcze nie wróciła;
 *   - własne blokady WYŁĄCZNIE z odpowiedzi koszyka (POST/DELETE/GET seat-locks).
 *
 * Kliknięte miejsce czeka na odpowiedź serwera ("pending"): wygląda jak przed kliknięciem, ma
 * wskaźnik oczekiwania i jest zablokowane. Dopiero 201 zmienia je na "wybrane", 409 na "zajęte".
 * Bez optymizmu (wymóg 3.2) i bez mylenia własnej blokady z cudzą, gdy zdarzenie przyjdzie przed odpowiedzią.
 */
import type { MapSeat, Money, SeatType } from '@/api/types';

export type SeatView = 'free' | 'selected' | 'held' | 'sold' | 'unavailable';

export interface SeatPresentation {
  view: SeatView;
  busy: boolean;
  /** Czy kliknięcie ma sens (wybór wolnego albo odkliknięcie własnego). */
  actionable: boolean;
}

export function presentSeat(seat: MapSeat, ownSeatIds: ReadonlySet<number>, pendingSeatIds: ReadonlySet<number>): SeatPresentation {
  const own = ownSeatIds.has(seat.id);
  const busy = pendingSeatIds.has(seat.id);

  let view: SeatView;
  if (own) {
    view = 'selected';
  } else if (busy) {
    // Zdarzenie "held" dla klikniętego miejsca mogło przyjść przed odpowiedzią — to może być nasza blokada.
    view = 'free';
  } else {
    view = seat.status === 'held_by_you' ? 'selected' : seat.status;
  }

  return { view, busy, actionable: !busy && (view === 'free' || view === 'selected') };
}

const VIEW_LABELS: Record<SeatView, string> = {
  free: 'wolne',
  selected: 'wybrane przez ciebie',
  held: 'zablokowane przez innego klienta',
  sold: 'sprzedane',
  unavailable: 'niedostępne',
};

const TYPE_LABELS: Record<SeatType, string> = {
  standard: '',
  double: 'miejsce podwójne',
  accessible: 'miejsce dla osoby z niepełnosprawnością',
};

/** "Rząd F, miejsce 7, miejsce podwójne, wolne, Premium, 22,40 zł" — dla czytników ekranu. */
export function seatAriaLabel(seat: MapSeat, presentation: SeatPresentation, price: Money | null = seat.price): string {
  return [
    `Rząd ${seat.row}, miejsce ${seat.number}`,
    TYPE_LABELS[seat.type],
    presentation.busy ? 'trwa rezerwowanie' : VIEW_LABELS[presentation.view],
    seat.category.name ?? '',
    price?.formatted ?? '',
  ].filter(Boolean).join(', ');
}
