import { describe, expect, it } from 'vitest';
import { addDays, dateOf, formatDayLabel, formatDuration, isDateString, timeOf, weekdayShort } from '@/lib/datetime';

describe('daty i godziny w strefie kina', () => {
  it('godzinę seansu bierze dosłownie z ISO 8601, bez przeliczania na strefę urządzenia (decyzja 24)', () => {
    expect(timeOf('2026-09-17T19:30:00+02:00')).toBe('19:30');
    expect(timeOf('2026-10-25T01:15:00-05:00')).toBe('01:15');
    expect(dateOf('2026-09-17T23:59:00+02:00')).toBe('2026-09-17');
  });

  it('dodaje dni przez granice miesiąca i roku oraz przez zmianę czasu', () => {
    expect(addDays('2026-09-29', 3)).toBe('2026-10-02');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addDays('2026-10-24', 2)).toBe('2026-10-26');
  });

  it('dzień tygodnia i etykieta po polsku', () => {
    expect(weekdayShort('2026-09-17')).toBe('cz');
    expect(formatDayLabel('2026-09-17')).toBe('czwartek, 17 września');
    expect(formatDuration(114)).toBe('1 h 54 min');
    expect(formatDuration(45)).toBe('45 min');
  });

  it('rozpoznaje wyłącznie istniejące daty RRRR-MM-DD', () => {
    expect(isDateString('2026-02-28')).toBe(true);
    expect(isDateString('2026-02-30')).toBe(false);
    expect(isDateString('17.09.2026')).toBe(false);
    expect(isDateString(undefined)).toBe(false);
  });
});
