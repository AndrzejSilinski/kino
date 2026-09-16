/*
 * Daty i godziny w katalogu (Etap 8, blok E).
 *
 * DECYZJA 24: czasy seansów przychodzą w strefie KINA z offsetem ("2026-09-17T19:30:00+02:00")
 * i wyświetlamy je DOSŁOWNIE. toLocaleString() przeliczyłby godzinę na strefę urządzenia —
 * klient z telefonem ustawionym na Londyn zobaczyłby seans o 18:30, na który trzeba przyjść o 19:30.
 *
 * Daty kalendarzowe (RRRR-MM-DD) liczymy w UTC: dodanie dnia nie może przeskoczyć o godzinę
 * przy zmianie czasu w strefie urządzenia.
 */
const WEEKDAYS_SHORT = ['nd', 'pn', 'wt', 'śr', 'cz', 'pt', 'sb'];
const WEEKDAYS = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
const MONTHS_GENITIVE = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const ISO_WITH_OFFSET = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/;

export function isDateString(value: unknown): value is string {
  if (typeof value !== 'string') {
    return false;
  }
  const match = DATE_PATTERN.exec(value);
  if (!match) {
    return false;
  }
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return date.toISOString().slice(0, 10) === value;
}

function toUtc(date: string): Date {
  const match = DATE_PATTERN.exec(date);
  if (!match) {
    throw new Error(`Nieprawidłowa data: ${date}`);
  }
  return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

export function addDays(date: string, days: number): string {
  const utc = toUtc(date);
  utc.setUTCDate(utc.getUTCDate() + days);
  return utc.toISOString().slice(0, 10);
}

export function weekdayShort(date: string): string {
  return WEEKDAYS_SHORT[toUtc(date).getUTCDay()] ?? '';
}

export function dayOfMonth(date: string): number {
  return toUtc(date).getUTCDate();
}

/** "czwartek, 17 września" */
export function formatDayLabel(date: string): string {
  const utc = toUtc(date);
  return `${WEEKDAYS[utc.getUTCDay()]}, ${utc.getUTCDate()} ${MONTHS_GENITIVE[utc.getUTCMonth()]}`;
}

/** Godzina "19:30" wprost z ISO 8601 w strefie kina — bez przeliczania na strefę urządzenia. */
export function timeOf(iso: string): string {
  const match = ISO_WITH_OFFSET.exec(iso);
  return match ? `${match[2]}:${match[3]}` : '';
}

/** Data kalendarzowa seansu w strefie kina. */
export function dateOf(iso: string): string {
  return ISO_WITH_OFFSET.exec(iso)?.[1] ?? '';
}

export function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return hours > 0 ? `${hours} h ${rest} min` : `${rest} min`;
}
