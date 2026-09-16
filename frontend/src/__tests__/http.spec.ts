import { describe, expect, it, vi } from 'vitest';
import { createHttpClient, filenameFromDisposition } from '@/api/http';
import { createBookingSessionStore } from '@/api/bookingSession';
import { ApiError } from '@/api/errors';

const SESSION_A = 'A'.repeat(32);
const SESSION_B = 'B'.repeat(32);

function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

function setup(token: string | null = null) {
  const fetchMock = vi.fn<typeof fetch>();
  const onUnauthorized = vi.fn();
  const sessions = createBookingSessionStore(null);
  const http = createHttpClient({ fetchImpl: fetchMock, getToken: () => token, bookingSession: sessions, onUnauthorized });
  const sent = (call = 0) => {
    const [url, init] = fetchMock.mock.calls[call] ?? [];
    return { url: String(url), init: init as RequestInit, headers: new Headers((init as RequestInit)?.headers) };
  };
  return { fetchMock, onUnauthorized, sessions, http, sent };
}

async function caught(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    if (error instanceof ApiError) {
      return error;
    }
    throw error;
  }
  throw new Error('oczekiwano ApiError');
}

describe('klient HTTP: sukces i nagłówki', () => {
  it('zwraca status i ciało, wysyła Accept: application/json i parametry zapytania', async () => {
    const { http, fetchMock, sent } = setup();
    fetchMock.mockResolvedValue(json(200, { data: { ok: true } }));

    const response = await http.request<{ data: { ok: boolean } }>('/cinemas/x/screenings', { query: { date: '2026-09-17', page: 2, pomin: undefined } });

    expect(response.status).toBe(200);
    expect(response.body.data.ok).toBe(true);
    expect(sent().url).toBe('/api/v1/cinemas/x/screenings?date=2026-09-17&page=2');
    expect(sent().headers.get('Accept')).toBe('application/json');
  });

  it('dokleja token bearer, chyba że żądanie ma auth: false; ciało wysyła jako JSON', async () => {
    const { http, fetchMock, sent } = setup('7|tokentestowy');
    // Nowy obiekt Response na każde wywołanie: ciało odpowiedzi da się przeczytać tylko raz.
    fetchMock.mockImplementation(async () => json(200, { data: {} }));

    await http.request('/auth/me');
    await http.request('/auth/login', { method: 'POST', body: { email: 'a@b.pl' }, auth: false });

    expect(sent(0).headers.get('Authorization')).toBe('Bearer 7|tokentestowy');
    expect(sent(1).headers.get('Authorization')).toBeNull();
    expect(sent(1).headers.get('Content-Type')).toBe('application/json');
    expect(sent(1).init.body).toBe('{"email":"a@b.pl"}');
  });

  it('odpowiedź 204 daje puste ciało', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

    await expect(http.request('/screenings/1/seat-locks/5', { method: 'DELETE' })).resolves.toMatchObject({ status: 204, body: null });
  });
});

describe('klient HTTP: błędy w jednym kształcie', () => {
  it('422 daje ApiError z kodem i błędami pól', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockResolvedValue(json(422, { message: 'Podane dane są nieprawidłowe.', code: 'VALIDATION_FAILED', errors: { email: ['Zły adres.'] } }));

    const error = await caught(http.request('/auth/register', { method: 'POST', body: {}, auth: false }));

    expect(error).toMatchObject({ status: 422, code: 'VALIDATION_FAILED', errors: { email: ['Zły adres.'] } });
  });

  it('401 UNAUTHENTICATED na żądaniu z tokenem woła onUnauthorized', async () => {
    const { http, fetchMock, onUnauthorized } = setup('1|stary');
    fetchMock.mockResolvedValue(json(401, { message: 'Wymagane jest zalogowanie.', code: 'UNAUTHENTICATED' }));

    await caught(http.request('/bookings'));

    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('401 INVALID_CREDENTIALS przy logowaniu NIE wylogowuje (to zła para e-mail i hasło)', async () => {
    const { http, fetchMock, onUnauthorized } = setup('1|inny-token');
    fetchMock.mockResolvedValue(json(401, { message: 'Nieprawidłowy e-mail lub hasło.', code: 'INVALID_CREDENTIALS' }));

    const error = await caught(http.request('/auth/login', { method: 'POST', body: {}, auth: false }));

    expect(error.code).toBe('INVALID_CREDENTIALS');
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('429 niesie liczbę sekund z nagłówka Retry-After', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockResolvedValue(json(429, { message: 'Zbyt wiele żądań.', code: 'TOO_MANY_REQUESTS', context: { retry_after_seconds: 60 } }, { 'Retry-After': '42' }));

    const error = await caught(http.request('/auth/login', { method: 'POST', body: {}, auth: false }));

    expect(error).toMatchObject({ code: 'TOO_MANY_REQUESTS', retryAfterSeconds: 42 });
  });

  it('brak sieci daje NETWORK_ERROR ze statusem 0', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(caught(http.request('/cinemas'))).resolves.toMatchObject({ status: 0, code: 'NETWORK_ERROR' });
  });

  it('strona 502 z nginx (nie JSON) daje SERVER_ERROR', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockResolvedValue(new Response('<html>502 Bad Gateway</html>', { status: 502, headers: { 'Content-Type': 'text/html' } }));

    await expect(caught(http.request('/cinemas'))).resolves.toMatchObject({ status: 502, code: 'SERVER_ERROR' });
  });

  it('przerwanie żądania (AbortController) nie jest zamieniane na błąd sieci', async () => {
    const { http, fetchMock } = setup();
    fetchMock.mockRejectedValue(new DOMException('aborted', 'AbortError'));

    await expect(http.request('/cinemas')).rejects.toMatchObject({ name: 'AbortError' });
  });
});

describe('klient HTTP: sesja zakupowa X-Session-Id', () => {
  it('zapamiętuje identyfikator nadany przez serwer i odsyła go w kolejnych żądaniach', async () => {
    const { http, fetchMock, sessions, sent } = setup();
    fetchMock.mockResolvedValueOnce(json(200, { data: {} }, { 'X-Session-Id': SESSION_A }));
    fetchMock.mockResolvedValueOnce(json(200, { data: {} }, { 'X-Session-Id': SESSION_A }));

    await http.request('/screenings/1/seat-map', { bookingSession: true });
    await http.request('/screenings/1/seat-locks', { bookingSession: true });

    expect(sent(0).headers.get('X-Session-Id')).toBeNull();
    expect(sent(1).headers.get('X-Session-Id')).toBe(SESSION_A);
    expect(sessions.get()).toBe(SESSION_A);
  });

  it('równoległe żądania bez sesji: drugie czeka na pierwsze i dostaje ten sam identyfikator', async () => {
    const { http, fetchMock, sent } = setup();
    let answerFirst!: (response: Response) => void;
    fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { answerFirst = resolve; }));
    fetchMock.mockResolvedValueOnce(json(200, { data: {} }, { 'X-Session-Id': SESSION_A }));

    const first = http.request('/screenings/1/seat-map', { bookingSession: true });
    const second = http.request('/screenings/1/seat-locks', { bookingSession: true });
    await Promise.resolve();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    answerFirst(json(200, { data: {} }, { 'X-Session-Id': SESSION_A }));
    await Promise.all([first, second]);

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(sent(1).headers.get('X-Session-Id')).toBe(SESSION_A);
  });

  it('INVALID_SESSION_ID: zapomina identyfikator i ponawia raz bez nagłówka', async () => {
    const { http, fetchMock, sessions, sent } = setup();
    sessions.set(SESSION_A);
    fetchMock.mockResolvedValueOnce(json(422, { message: 'Nieprawidłowy identyfikator sesji zakupowej.', code: 'INVALID_SESSION_ID' }));
    fetchMock.mockResolvedValueOnce(json(200, { data: {} }, { 'X-Session-Id': SESSION_B }));

    await http.request('/screenings/1/seat-map', { bookingSession: true });

    expect(sent(0).headers.get('X-Session-Id')).toBe(SESSION_A);
    expect(sent(1).headers.get('X-Session-Id')).toBeNull();
    expect(sessions.get()).toBe(SESSION_B);
  });
});

describe('klient HTTP: pliki', () => {
  it('requestBlob zwraca plik i nazwę z Content-Disposition', async () => {
    const { http, fetchMock } = setup('1|t');
    fetchMock.mockResolvedValue(new Response('%PDF', { status: 200, headers: { 'Content-Disposition': 'attachment; filename=bilety-01ABC.pdf' } }));

    const file = await http.requestBlob('/bookings/01ABC/tickets/pdf');

    expect(file.filename).toBe('bilety-01ABC.pdf');
    expect(await file.blob.text()).toBe('%PDF');
  });

  it('filename* (UTF-8) ma pierwszeństwo przed filename', () => {
    expect(filenameFromDisposition(`attachment; filename="bilety.pdf"; filename*=UTF-8''bilety-%C5%82%C3%B3d%C5%BA.pdf`)).toBe('bilety-łódź.pdf');
    expect(filenameFromDisposition(null)).toBeNull();
  });
});
