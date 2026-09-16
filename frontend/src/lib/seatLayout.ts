/*
 * Układ planu sali na siatce CSS (Etap 8, blok F) — czysta logika bez Vue.
 *
 * Współrzędne z panelu admina liczone od 1. Kolumna 1 i ostatnia to etykiety rzędów, więc miejsce
 * z x trafia do kolumny x + 1. Miejsce podwójne zajmuje kratki x i x+1 (decyzja 149) — "span 2".
 * Przejścia to po prostu puste kratki: nie rysujemy ich, siatka zostawia miejsce sama.
 */
import type { MapSeat } from '@/api/types';

export interface PlacedSeat {
  seat: MapSeat;
  column: number;
  row: number;
  span: 1 | 2;
}

export interface SeatRow {
  y: number;
  label: string;
  seats: PlacedSeat[];
}

export interface SeatLayout {
  columns: number;
  rows: SeatRow[];
  /** Miejsca poza wymiarami sali (błąd danych) — pomijamy je zamiast rozsypywać siatkę. */
  skipped: number[];
}

export function buildSeatLayout(seats: MapSeat[], grid: { rows: number; columns: number }): SeatLayout {
  const byRow = new Map<number, PlacedSeat[]>();
  const skipped: number[] = [];

  for (const seat of seats) {
    const span: 1 | 2 = seat.type === 'double' ? 2 : 1;
    const { x, y } = seat.position;
    if (x < 1 || y < 1 || y > grid.rows || x + span - 1 > grid.columns) {
      skipped.push(seat.id);
      continue;
    }
    const placed = { seat, column: x + 1, row: y, span };
    byRow.set(y, [...(byRow.get(y) ?? []), placed]);
  }

  const rows = [...byRow.entries()]
    .sort(([a], [b]) => a - b)
    .map(([y, placed]) => ({
      y,
      label: placed[0]?.seat.row ?? '',
      seats: placed.sort((a, b) => a.column - b.column),
    }));

  return { columns: grid.columns, rows, skipped };
}
