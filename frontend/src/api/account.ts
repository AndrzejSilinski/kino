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

export interface AccountApi {
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
    async notificationSettings() {
      return (await http.request<Envelope<NotificationSettings>>('/account/notifications')).body.data;
    },
    async updateNotificationSettings(changes) {
      return (await http.request<Envelope<NotificationSettings>>('/account/notifications', { method: 'PATCH', body: changes })).body.data;
    },
  };
}
