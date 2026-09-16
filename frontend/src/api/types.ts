/*
 * Typy odpowiedzi API (Etap 8). Źródło: Resources i kontrolery Laravela, nie domysły.
 * Koperta: sukces zawsze w `data`; listy stronicowane dokładają `links` i `meta`.
 */
export interface Envelope<T> {
  data: T;
}

export interface Money {
  amount: number;
  currency: string;
  formatted: string;
}

export type UserRole = 'customer' | 'admin' | 'staff';

/** UserResource */
export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  created_at: string | null;
}

/** AuthController::tokenResponse */
export interface AuthToken {
  user: User;
  token: string;
  token_type: 'Bearer';
}

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

/** Lista stronicowana Laravela (links + meta). */
export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/** CinemaResource */
export interface Cinema {
  id: number;
  slug: string;
  name: string;
  city: string;
  address: string;
  timezone: string;
}

/** GET /cinemas — kina pogrupowane po miastach. */
export interface CinemaGroup {
  city: string;
  cinemas: Cinema[];
}

/** GET /cinemas/{slug}/screening-dates */
export interface ScreeningDate {
  date: string;
  screenings_count: number;
}

/** ScreeningListItemResource. Czasy w strefie KINA z offsetem — wyświetlamy dosłownie (decyzja 24). */
export interface ScreeningListItem {
  id: number;
  starts_at: string;
  ends_at: string;
  projection_type: string;
  projection_type_label: string;
  language_version: string;
  language_version_label: string;
  hall: { id: number; name: string };
  movie: {
    id: number;
    slug: string;
    title: string;
    duration_minutes: number;
    age_rating: string;
    poster_url: string | null;
  };
  seats: { total: number; taken: number; available: number };
  is_sold_out: boolean;
  has_started: boolean;
  is_bookable: boolean;
}

export interface PriceCategory {
  id: number;
  slug: string;
  name: string;
  color: string;
}

/** ScreeningResource (GET /screenings/{id}, także screening w planie sali). */
export interface ScreeningDetails {
  id: number;
  starts_at: string;
  ends_at: string;
  projection_type: string;
  projection_type_label: string;
  language_version: string;
  language_version_label: string;
  status: string;
  is_bookable: boolean;
  movie: {
    id: number;
    slug: string;
    title: string;
    original_title: string | null;
    description: string | null;
    duration_minutes: number;
    age_rating: string;
    genres: string[];
    premiere_date: string | null;
    poster_url: string | null;
  };
  hall: {
    id: number;
    name: string;
    grid: { rows: number; columns: number };
    projection_types: string[];
    cinema: Cinema;
  };
  prices: { category: PriceCategory; price: Money }[];
}

export type SeatStatus = 'free' | 'held' | 'held_by_you' | 'sold' | 'unavailable';
export type SeatType = 'standard' | 'double' | 'accessible';

/** Miejsce w planie sali (SeatMapService::build). Pozycje liczone od 1; podwójne zajmuje kratki x i x+1. */
export interface MapSeat {
  id: number;
  row: string;
  number: number;
  label: string;
  type: SeatType;
  type_label: string;
  position: { x: number; y: number };
  category: { id: number | null; name: string | null; color: string | null };
  price: Money | null;
  status: SeatStatus;
  /** Tylko dla własnej blokady — cudzy czas wygaśnięcia byłby wyciekiem informacji o cudzym koszyku. */
  lock_expires_at: string | null;
  lock_expires_in_seconds: number | null;
}

/** GET /screenings/{id}/seat-map */
export interface SeatMapSnapshot {
  screening: ScreeningDetails;
  seats: MapSeat[];
  summary: Record<SeatStatus, number> & { total: number };
  seat_state_version: number;
}

/** Pozycja koszyka (CartPricingService::forSession). */
export interface CartSeat {
  seat_id: number;
  row: string;
  number: number;
  label: string;
  type: SeatType;
  category: { id: number | null; name: string | null; color: string | null };
  price: Money;
  lock_expires_at: string;
}

/** Koszyk: GET/POST/DELETE /screenings/{id}/seat-locks. Timer = najwcześniejsza blokada. */
export interface Cart {
  seats: CartSeat[];
  seats_count: number;
  total: Money;
  expires_at: string | null;
  expires_in_seconds: number | null;
}
