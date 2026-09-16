import { describe, expect, it } from 'vitest';
import { buildSeatLayout } from '@/lib/seatLayout';
import { mapSeat } from './fixtures/seats';

describe('układ planu sali', () => {
  it('miejsce podwójne zajmuje dwie kratki, przejścia zostają pustymi kratkami, kolumny przesunięte o etykietę rzędu', () => {
    const layout = buildSeatLayout([
      mapSeat({ id: 3, position: { x: 4, y: 2 }, row: 'B', type: 'double' }),
      mapSeat({ id: 1, position: { x: 1, y: 1 }, row: 'A' }),
      mapSeat({ id: 2, position: { x: 3, y: 1 }, row: 'A' }),
    ], { rows: 2, columns: 6 });

    expect(layout.rows.map((row) => row.label)).toEqual(['A', 'B']);
    expect(layout.rows[0]?.seats.map((placed) => [placed.seat.id, placed.column, placed.span])).toEqual([[1, 2, 1], [2, 4, 1]]);
    expect(layout.rows[1]?.seats[0]).toMatchObject({ column: 5, row: 2, span: 2 });
  });

  it('pomija miejsca poza wymiarami sali (także podwójne wystające za ostatnią kolumnę)', () => {
    const layout = buildSeatLayout([
      mapSeat({ id: 1, position: { x: 6, y: 1 }, type: 'double' }),
      mapSeat({ id: 2, position: { x: 1, y: 3 } }),
      mapSeat({ id: 3, position: { x: 5, y: 1 }, type: 'double' }),
    ], { rows: 2, columns: 6 });

    expect(layout.skipped).toEqual([1, 2]);
    expect(layout.rows[0]?.seats.map((placed) => placed.seat.id)).toEqual([3]);
  });
});
