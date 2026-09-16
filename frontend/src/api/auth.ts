/*
 * Konto: logowanie, rejestracja, wylogowanie, dane użytkownika (API z Etapu 3).
 */
import type { HttpClient } from './http';
import type { AuthToken, Envelope, RegisterPayload, User } from './types';

/** Nazwa tokenu Sanctum widoczna w bazie (jeden token = jedno urządzenie, decyzja 17). */
export const WEB_DEVICE_NAME = 'Przeglądarka (web)';

export interface AuthApi {
  login(email: string, password: string): Promise<AuthToken>;
  register(payload: RegisterPayload): Promise<AuthToken>;
  logout(): Promise<void>;
  me(): Promise<User>;
}

export function createAuthApi(http: HttpClient): AuthApi {
  return {
    async login(email, password) {
      const response = await http.request<Envelope<AuthToken>>('/auth/login', {
        method: 'POST',
        body: { email, password, device_name: WEB_DEVICE_NAME },
        auth: false,
      });
      return response.body.data;
    },
    async register(payload) {
      const response = await http.request<Envelope<AuthToken>>('/auth/register', {
        method: 'POST',
        body: { ...payload, device_name: WEB_DEVICE_NAME },
        auth: false,
      });
      return response.body.data;
    },
    async logout() {
      await http.request('/auth/logout', { method: 'POST' });
    },
    async me() {
      const response = await http.request<Envelope<User>>('/auth/me');
      return response.body.data;
    },
  };
}
