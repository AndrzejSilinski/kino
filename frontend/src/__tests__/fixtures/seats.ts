import type { Cart, CartSeat, MapSeat, ScreeningDetails, SeatMapSnapshot } from '@/api/types';

export function mapSeat(overrides: Partial<MapSeat> = {}): MapSeat {
  const x = overrides.position?.x ?? 1;
  return {
    id: 890 + x,
    row: 'A',
    number: x,
    label: `A${x}`,
    type: 'standard',
    type_label: 'Miejsce standardowe',
    position: { x, y: 1 },
    category: { id: 1, name: 'Standardowe', color: '#4B5563' },
    price: { amount: 1760, currency: 'PLN', formatted: '17,60 zł' },
    status: 'free',
    lock_expires_at: null,
    lock_expires_in_seconds: null,
    ...overrides,
  };
}

export const screeningDetails: ScreeningDetails = {
  id: 334,
  starts_at: '2026-09-17T11:00:00+02:00',
  ends_at: '2026-09-17T13:09:00+02:00',
  projection_type: '2d',
  projection_type_label: '2D',
  language_version: 'subtitles',
  language_version_label: 'Napisy polskie',
  status: 'scheduled',
  is_bookable: true,
  movie: { id: 6, slug: 'barbie', title: 'Barbie', original_title: 'Barbie', description: null, duration_minutes: 114, age_rating: '12', genres: [], premiere_date: null, poster_url: null },
  hall: { id: 6, name: 'Sala A', grid: { rows: 2, columns: 6 }, projection_types: ['2d'], cinema: { id: 3, slug: 'gdansk-kino-baltyk', name: 'Kino Bałtyk', city: 'Gdańsk', address: 'al. Grunwaldzka 82', timezone: 'Europe/Warsaw' } },
  prices: [],
};

export function snapshot(seats: MapSeat[], version = 5): SeatMapSnapshot {
  return {
    screening: screeningDetails,
    seats,
    summary: { free: seats.length, held: 0, held_by_you: 0, sold: 0, unavailable: 0, total: seats.length },
    seat_state_version: version,
  };
}

export function cartOf(seatIds: number[], expiresIn: number | null = 600): Cart {
  const seats: CartSeat[] = seatIds.map((id) => ({
    seat_id: id,
    row: 'A',
    number: id - 890,
    label: `A${id - 890}`,
    type: 'standard',
    category: { id: 1, name: 'Standardowe', color: '#4B5563' },
    price: { amount: 1760, currency: 'PLN', formatted: '17,60 zł' },
    lock_expires_at: '2026-09-16T21:10:00+00:00',
  }));
  const total = 1760 * seatIds.length;
  return {
    seats,
    seats_count: seats.length,
    total: { amount: total, currency: 'PLN', formatted: `${(total / 100).toFixed(2).replace('.', ',')} zł` },
    expires_at: seatIds.length > 0 ? '2026-09-16T21:10:00+00:00' : null,
    expires_in_seconds: seatIds.length > 0 ? expiresIn : null,
  };
}

/** Obietnica rozwiązywana ręcznie — do symulowania kolejności odpowiedzi serwera. */
export function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}
