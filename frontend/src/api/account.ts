/*
 * Konto zalogowanego klienta (Etap 8, blok J; API z bloku I).
 * Wszystkie trasy działają na właścicielu tokenu — bez identyfikatora konta w adresie.
 */
import type { HttpClient } from './http';
import type { ChangePasswordPayload, Envelope, NotificationSettings, User } from './types';

export interface PasswordChanged {
  message: string;
  /** Ile innych urządzeń zostało wylogowanych. */
  revoked_tokens: number;
}

export type PushPlatform = 'web' | 'android' | 'ios';

/** PushDeviceResource (blok K). Bez tokenu FCM — klient go zna, a API go nie odsyła. */
export interface PushDevice {
  id: string;
  platform: PushPlatform;
  last_seen_at: string;
  created_at: string | null;
}

export interface AccountApi {
  /** PUT /account/devices — rejestracja albo odświeżenie (replaces = poprzedni token tej instalacji). */
  registerDevice(token: string, replaces?: string | null): Promise<PushDevice>;
  unregisterDevice(id: string): Promise<void>;
  updateProfile(name: string): Promise<User>;
  changePassword(payload: ChangePasswordPayload): Promise<PasswordChanged>;
  uploadAvatar(file: File): Promise<User>;
  deleteAvatar(): Promise<User>;
  notificationSettings(): Promise<NotificationSettings>;
  updateNotificationSettings(changes: Partial<Pick<NotificationSettings, 'push_enabled' | 'screening_reminders'>>): Promise<NotificationSettings>;
}

export function createAccountApi(http: HttpClient): AccountApi {
  return {
    async updateProfile(name) {
      return (await http.request<Envelope<User>>('/account/profile', { method: 'PATCH', body: { name } })).body.data;
    },
    async changePassword(payload) {
      return (await http.request<Envelope<PasswordChanged>>('/account/password', { method: 'PUT', body: payload })).body.data;
    },
    async uploadAvatar(file) {
      const form = new FormData();
      form.append('avatar', file);
      return (await http.request<Envelope<User>>('/account/avatar', { method: 'POST', formData: form })).body.data;
    },
    async deleteAvatar() {
      return (await http.request<Envelope<User>>('/account/avatar', { method: 'DELETE' })).body.data;
    },
    async registerDevice(token, replaces = null) {
      const body = { token, platform: 'web', ...(replaces ? { replaces } : {}) };
      return (await http.request<Envelope<PushDevice>>('/account/devices', { method: 'PUT', body })).body.data;
    },
    async unregisterDevice(id) {
      await http.request(`/account/devices/${encodeURIComponent(id)}`, { method: 'DELETE' });
    },
    async notificationSettings() {
      return (await http.request<Envelope<NotificationSettings>>('/account/notifications')).body.data;
    },
    async updateNotificationSettings(changes) {
      return (await http.request<Envelope<NotificationSettings>>('/account/notifications', { method: 'PATCH', body: changes })).body.data;
    },
  };
}
