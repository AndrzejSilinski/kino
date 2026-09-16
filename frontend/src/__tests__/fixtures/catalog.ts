import type { ScreeningListItem } from '@/api/types';

/** Seans w kształcie ScreeningListItemResource (z rozpoznania A: Kino Bałtyk, 2026-09-17). */
export function screening(overrides: Partial<ScreeningListItem> & { movieId?: number; title?: string } = {}): ScreeningListItem {
  const { movieId = 6, title = 'Barbie', ...rest } = overrides;
  return {
    id: 334,
    starts_at: '2026-09-17T11:00:00+02:00',
    ends_at: '2026-09-17T13:09:00+02:00',
    projection_type: '2d',
    projection_type_label: '2D',
    language_version: 'subtitles',
    language_version_label: 'Napisy polskie',
    hall: { id: 6, name: 'Sala A' },
    movie: { id: movieId, slug: `film-${movieId}`, title, duration_minutes: 114, age_rating: '12', poster_url: null },
    seats: { total: 90, taken: 0, available: 90 },
    is_sold_out: false,
    has_started: false,
    is_bookable: true,
    ...rest,
  };
}
