import { describe, expect, it } from 'vitest';
import { presentSeat, seatAriaLabel } from '@/lib/seatState';
import { mapSeat } from './fixtures/seats';

const none = new Set<number>();

describe('stan miejsca widziany przez klienta', () => {
  it('zdarzenie "held" dla klikniętego miejsca przed odpowiedzią HTTP nie maluje go jako cudzego', () => {
    const seat = mapSeat({ id: 7, status: 'held' });

    expect(presentSeat(seat, none, new Set([7]))).toEqual({ view: 'free', busy: true, actionable: false });
    expect(presentSeat(seat, new Set([7]), none)).toEqual({ view: 'selected', busy: false, actionable: true });
    expect(presentSeat(seat, none, none)).toEqual({ view: 'held', busy: false, actionable: false });
  });

  it('własna blokada z migawki (held_by_you) jest wybrana; sprzedane i niedostępne nie są klikalne', () => {
    expect(presentSeat(mapSeat({ status: 'held_by_you' }), none, none).view).toBe('selected');
    expect(presentSeat(mapSeat({ status: 'sold' }), none, none)).toMatchObject({ view: 'sold', actionable: false });
    expect(presentSeat(mapSeat({ status: 'unavailable' }), none, none)).toMatchObject({ view: 'unavailable', actionable: false });
  });

  it('etykieta dla czytnika ekranu: rząd, numer, typ, stan, kategoria i cena z serwera', () => {
    const seat = mapSeat({ row: 'F', number: 7, type: 'double', category: { id: 4, name: 'Miejsce podwójne', color: null }, price: { amount: 3200, currency: 'PLN', formatted: '32,00 zł' } });

    expect(seatAriaLabel(seat, presentSeat(seat, none, none))).toBe('Rząd F, miejsce 7, miejsce podwójne, wolne, Miejsce podwójne, 32,00 zł');
    expect(seatAriaLabel(seat, presentSeat(seat, none, new Set([seat.id])))).toContain('trwa rezerwowanie');
  });
});
