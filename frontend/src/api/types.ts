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
