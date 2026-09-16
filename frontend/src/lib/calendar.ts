/*
 * Kalendarz dni z repertuarem (Etap 8, blok E) — czysta logika bez Vue.
 *
 * Serwer zwraca tylko dni, w których są seanse (okno 14 dni, RepertoireService::HORIZON_DAYS)
 * oraz "dziś" w strefie kina. Pokazujemy CAŁE okno od dziś: dni bez seansów są widoczne,
 * ale wyłączone — klient widzi, że np. w środę kino nie gra, zamiast zgadywać.
 */
import { addDays, dayOfMonth, formatDayLabel, isDateString, weekdayShort } from './datetime';
import type { ScreeningDate } from '@/api/types';

export const CALENDAR_DAYS = 14;

export interface CalendarDay {
  date: string;
  weekday: string;
  day: number;
  label: string;
  screenings: number;
  isToday: boolean;
  hasScreenings: boolean;
}

export function buildCalendar(today: string, available: ScreeningDate[], length = CALENDAR_DAYS): CalendarDay[] {
  const counts = new Map(available.map((item) => [item.date, item.screenings_count]));
  const lastAvailable = available.reduce((last, item) => (item.date > last ? item.date : last), today);
  const days: CalendarDay[] = [];

  for (let offset = 0; ; offset++) {
    const date = addDays(today, offset);
    if (offset >= length && date > lastAvailable) {
      break;
    }
    const screenings = counts.get(date) ?? 0;
    days.push({
      date,
      weekday: weekdayShort(date),
      day: dayOfMonth(date),
      label: formatDayLabel(date),
      screenings,
      isToday: offset === 0,
      hasScreenings: screenings > 0,
    });
  }

  return days;
}

/** Wybrany dzień: z adresu (?date=), jeśli ma seanse; inaczej pierwszy dzień z seansami; inaczej dziś. */
export function pickDate(requested: unknown, days: CalendarDay[]): string | null {
  if (isDateString(requested) && days.some((day) => day.date === requested && day.hasScreenings)) {
    return requested;
  }
  return days.find((day) => day.hasScreenings)?.date ?? days[0]?.date ?? null;
}
