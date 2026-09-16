import { describe, expect, it } from 'vitest';
import { groupByMovie, isOpenForBooking, showtimeState, showtimeStatusLabel } from '@/lib/repertoire';
import { screening } from './fixtures/catalog';

describe('repertuar dnia', () => {
  it('grupuje seanse po filmach w kolejności najwcześniejszego seansu, godziny rosnąco', () => {
    const groups = groupByMovie([
      screening({ id: 3, movieId: 7, title: 'Toy Story 4', starts_at: '2026-09-17T13:30:00+02:00' }),
      screening({ id: 2, movieId: 6, title: 'Barbie', starts_at: '2026-09-17T18:00:00+02:00' }),
      screening({ id: 1, movieId: 6, title: 'Barbie', starts_at: '2026-09-17T11:00:00+02:00' }),
    ]);

    expect(groups.map((group) => group.movie.title)).toEqual(['Barbie', 'Toy Story 4']);
    expect(groups[0]?.screenings.map((item) => item.id)).toEqual([1, 2]);
  });

  it('stan seansu: wyprzedany i rozpoczęty są zamknięte, mała liczba miejsc to ostrzeżenie', () => {
    const soldOut = screening({ is_sold_out: true, is_bookable: false, seats: { total: 90, taken: 90, available: 0 } });
    const started = screening({ has_started: true, is_bookable: false });
    const fewLeft = screening({ seats: { total: 90, taken: 85, available: 5 } });

    expect([showtimeState(soldOut), isOpenForBooking(soldOut), showtimeStatusLabel(soldOut)]).toEqual(['sold-out', false, 'Wyprzedane']);
    expect([showtimeState(started), isOpenForBooking(started)]).toEqual(['started', false]);
    expect([showtimeState(fewLeft), isOpenForBooking(fewLeft), showtimeStatusLabel(fewLeft)]).toEqual(['few-left', true, 'Ostatnie miejsca: 5']);
    expect(showtimeStatusLabel(screening())).toBe('Wolne miejsca: 90');
  });
});
