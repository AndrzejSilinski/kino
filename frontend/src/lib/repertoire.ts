/*
 * Repertuar dnia pogrupowany po filmach (Etap 8, blok E) — czysta logika bez Vue.
 *
 * Karta = film (plakat, tytuł, kategoria wiekowa, czas trwania), a w niej godziny seansów
 * z typem projekcji i wersją językową. Seans wyprzedany albo rozpoczęty jest wyszarzony
 * i NIEKLIKALNY (wymóg 3.1) — o tym decydują flagi z serwera, nie zegar urządzenia.
 */
import type { ScreeningListItem } from '@/api/types';

/** Poniżej tylu wolnych miejsc pokazujemy ostrzeżenie "ostatnie miejsca". */
export const FEW_SEATS_THRESHOLD = 10;

export type ShowtimeState = 'available' | 'few-left' | 'sold-out' | 'started';

export interface MovieRepertoire {
  movie: ScreeningListItem['movie'];
  screenings: ScreeningListItem[];
}

export function groupByMovie(screenings: ScreeningListItem[]): MovieRepertoire[] {
  const sorted = [...screenings].sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at) || a.id - b.id);
  const groups = new Map<number, MovieRepertoire>();

  for (const screening of sorted) {
    const group = groups.get(screening.movie.id);
    if (group) {
      group.screenings.push(screening);
    } else {
      groups.set(screening.movie.id, { movie: screening.movie, screenings: [screening] });
    }
  }

  // Kolejność filmów: według najwcześniejszego seansu (Map zachowuje kolejność wstawienia).
  return [...groups.values()];
}

export function showtimeState(screening: ScreeningListItem): ShowtimeState {
  if (screening.has_started) {
    return 'started';
  }
  if (screening.is_sold_out || !screening.is_bookable) {
    return 'sold-out';
  }
  return screening.seats.available < FEW_SEATS_THRESHOLD ? 'few-left' : 'available';
}

/** Czy godzinę seansu pokazujemy jako link do planu sali. */
export function isOpenForBooking(screening: ScreeningListItem): boolean {
  const state = showtimeState(screening);
  return state === 'available' || state === 'few-left';
}

export function showtimeStatusLabel(screening: ScreeningListItem): string {
  switch (showtimeState(screening)) {
    case 'started':
      return 'Seans już się rozpoczął';
    case 'sold-out':
      return 'Wyprzedane';
    case 'few-left':
      return `Ostatnie miejsca: ${screening.seats.available}`;
    default:
      return `Wolne miejsca: ${screening.seats.available}`;
  }
}
