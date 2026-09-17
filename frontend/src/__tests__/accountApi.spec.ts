import { describe, expect, it, vi } from 'vitest';
import { createAccountApi } from '@/api/account';
import { createBookingSessionStore } from '@/api/bookingSession';
import { createHttpClient } from '@/api/http';
import { user } from './fixtures/account';

function setup(body: unknown) {
  const fetchMock = vi.fn<typeof fetch>(async () => new Response(JSON.stringify({ data: body }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  const http = createHttpClient({ fetchImpl: fetchMock, getToken: () => 'token-testowy', bookingSession: createBookingSessionStore(null), onUnauthorized: vi.fn() });
  const sent = () => {
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    const request = init as RequestInit;
    return { url: String(url), method: request.method, headers: new Headers(request.headers), body: request.body };
  };
  return { api: createAccountApi(http), sent };
}

describe('API konta', () => {
  it('profil: PATCH z samym imieniem', async () => {
    const { api, sent } = setup(user({ name: 'Anna Kowalska' }));

    expect((await api.updateProfile('Anna Kowalska')).name).toBe('Anna Kowalska');
    expect([sent().url, sent().method, sent().body]).toEqual(['/api/v1/account/profile', 'PATCH', JSON.stringify({ name: 'Anna Kowalska' })]);
  });

  it('hasło: PUT z obecnym, nowym i powtórzeniem; odpowiedź mówi, ile urządzeń wylogowano', async () => {
    const { api, sent } = setup({ message: 'Hasło zostało zmienione.', revoked_tokens: 2 });

    const result = await api.changePassword({ current_password: 'stare1234', password: 'nowe12345', password_confirmation: 'nowe12345' });

    expect(result.revoked_tokens).toBe(2);
    expect([sent().url, sent().method]).toEqual(['/api/v1/account/password', 'PUT']);
    expect(JSON.parse(String(sent().body))).toEqual({ current_password: 'stare1234', password: 'nowe12345', password_confirmation: 'nowe12345' });
  });

  it('avatar: multipart z polem "avatar" — Content-Type z granicą ustawia przeglądarka, nie klient', async () => {
    const { api, sent } = setup(user());
    const file = new File(['x'], 'ja.png', { type: 'image/png' });

    await api.uploadAvatar(file);

    expect([sent().url, sent().method]).toEqual(['/api/v1/account/avatar', 'POST']);
    expect(sent().body).toBeInstanceOf(FormData);
    expect((sent().body as FormData).get('avatar')).toBe(file);
    expect(sent().headers.get('Content-Type')).toBeNull();
  });

  it('powiadomienia: PATCH tylko zmienianego pola', async () => {
    const { api, sent } = setup({ push_enabled: false, push_consent_at: null, screening_reminders: false });

    await api.updateNotificationSettings({ screening_reminders: false });

    expect([sent().url, sent().method, sent().body]).toEqual(['/api/v1/account/notifications', 'PATCH', JSON.stringify({ screening_reminders: false })]);
  });
});
