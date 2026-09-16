import { describe, expect, it } from 'vitest';
import { buildCalendar, pickDate } from '@/lib/calendar';

const available = [
  { date: '2026-09-16', screenings_count: 2 },
  { date: '2026-09-18', screenings_count: 8 },
];

describe('kalendarz dni z repertuarem', () => {
  it('pokazuje 14 dni od dziś i oznacza dni z seansami', () => {
    const days = buildCalendar('2026-09-16', available);

    expect(days).toHaveLength(14);
    expect(days[0]).toMatchObject({ date: '2026-09-16', isToday: true, hasScreenings: true, screenings: 2 });
    expect(days[1]).toMatchObject({ date: '2026-09-17', hasScreenings: false, screenings: 0, weekday: 'cz' });
    expect(days[13]?.date).toBe('2026-09-29');
  });

  it('wydłuża okno, gdy serwer zwraca dzień dalej niż 14 dni', () => {
    const days = buildCalendar('2026-09-16', [{ date: '2026-10-01', screenings_count: 1 }]);

    expect(days.at(-1)).toMatchObject({ date: '2026-10-01', hasScreenings: true });
  });

  it('wybiera dzień z adresu tylko wtedy, gdy ma seanse; inaczej pierwszy dzień z seansami', () => {
    const days = buildCalendar('2026-09-16', available);

    expect(pickDate('2026-09-18', days)).toBe('2026-09-18');
    expect(pickDate('2026-09-17', days)).toBe('2026-09-16');
    expect(pickDate('bzdura', days)).toBe('2026-09-16');
    expect(pickDate(undefined, buildCalendar('2026-09-16', []))).toBe('2026-09-16');
  });
});
