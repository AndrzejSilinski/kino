/*
 * Jeden klient HTTP dla całego SPA (Etap 8, blok D).
 *
 * Własny wrapper na fetch zamiast axios: potrzebujemy bloba (PDF, kody QR), AbortController
 * i keepalive, a to wszystko jest w fetch bez dodatkowej zależności.
 *
 * Co robi w jednym miejscu, żeby widoki i store'y tego nie powtarzały:
 *   - dokleja token bearer (jeśli jest) i nagłówek X-Session-Id (trasy koszyka),
 *   - zapamiętuje identyfikator sesji zakupowej z nagłówka odpowiedzi,
 *   - zamienia każdą porażkę na ApiError z polem `code` (także brak sieci i stronę 502 z nginx),
 *   - przy 401 UNAUTHENTICATED na żądaniu z tokenem woła onUnauthorized (token wygasł albo
 *     został unieważniony) — ale NIE przy nieudanym logowaniu (401 INVALID_CREDENTIALS).
 */
import { ApiError } from './errors';
import { BOOKING_SESSION_HEADER, type BookingSessionStore } from './bookingSession';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
  method?: HttpMethod;
  /** Ciało JSON. */
  body?: unknown;
  /** Ciało multipart (np. avatar); Content-Type ustawia przeglądarka razem z granicą. */
  formData?: FormData;
  query?: Record<string, string | number | boolean | null | undefined>;
  /** Czy dołączyć token, jeśli użytkownik jest zalogowany (domyślnie tak). */
  auth?: boolean;
  /** Czy to trasa koszyka: nagłówek X-Session-Id w żądaniu i w odpowiedzi. */
  bookingSession?: boolean;
  signal?: AbortSignal;
  keepalive?: boolean;
}

export interface ApiResponse<T> {
  status: number;
  body: T;
  headers: Headers;
}

export interface BlobResponse {
  blob: Blob;
  filename: string | null;
  headers: Headers;
}

export interface HttpClientOptions {
  baseUrl?: string;
  fetchImpl?: typeof fetch;
  getToken: () => string | null;
  bookingSession: BookingSessionStore;
  onUnauthorized: () => void;
}

export interface HttpClient {
  request<T>(path: string, options?: RequestOptions): Promise<ApiResponse<T>>;
  requestBlob(path: string, options?: RequestOptions): Promise<BlobResponse>;
}

interface ErrorBody {
  message: string;
  code: string;
  context?: Record<string, unknown>;
  errors?: Record<string, string[]>;
}

function isErrorBody(value: unknown): value is ErrorBody {
  return typeof value === 'object' && value !== null
    && typeof (value as ErrorBody).code === 'string'
    && typeof (value as ErrorBody).message === 'string';
}

/** Nazwa pliku z Content-Disposition: najpierw filename* (RFC 5987, UTF-8), potem filename. */
export function filenameFromDisposition(header: string | null): string | null {
  if (!header) {
    return null;
  }
  const extended = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header);
  if (extended?.[1]) {
    try {
      return decodeURIComponent(extended[1].trim());
    } catch {
      // Uszkodzone kodowanie — spróbujemy zwykłego filename.
    }
  }
  const plain = /filename\s*=\s*("([^"]*)"|[^;]+)/i.exec(header);
  const value = plain?.[2] ?? plain?.[1];
  return value ? value.trim() : null;
}

function retryAfterFrom(response: Response, body: unknown): number | null {
  const header = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
  if (Number.isFinite(header)) {
    return header;
  }
  const fromBody = isErrorBody(body) ? Number(body.context?.retry_after_seconds) : Number.NaN;
  return Number.isFinite(fromBody) ? fromBody : null;
}

async function errorFrom(response: Response): Promise<ApiError> {
  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    // Ciało nie jest JSON-em (np. strona 502 z nginx) — obsłużymy niżej.
  }

  if (isErrorBody(body)) {
    return new ApiError({
      status: response.status,
      code: body.code,
      message: body.message,
      context: body.context ?? {},
      errors: body.errors ?? {},
      retryAfterSeconds: retryAfterFrom(response, body),
    });
  }

  return new ApiError({
    status: response.status,
    code: response.status >= 500 ? 'SERVER_ERROR' : 'INVALID_RESPONSE',
    message: response.status >= 500
      ? 'Serwer jest chwilowo niedostępny. Spróbuj ponownie za chwilę.'
      : 'Nieoczekiwana odpowiedź serwera.',
    retryAfterSeconds: retryAfterFrom(response, null),
  });
}

export function createHttpClient(options: HttpClientOptions): HttpClient {
  const baseUrl = options.baseUrl ?? '/api/v1';
  const fetchImpl = options.fetchImpl ?? ((input, init) => fetch(input, init));
  const sessions = options.bookingSession;

  // Pierwsze żądanie koszyka bez identyfikatora sesji jest "bramką": kolejne czekają na nie,
  // żeby serwer nie wydał dwóch różnych sesji dwóm równoległym żądaniom tej samej karty.
  let sessionGate: Promise<void> | null = null;

  function buildUrl(path: string, query: RequestOptions['query']): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== null) {
        params.append(key, String(value));
      }
    }
    const search = params.toString();
    return `${baseUrl}${path}${search ? `?${search}` : ''}`;
  }

  async function send(path: string, opts: RequestOptions, sessionId: string | null): Promise<{ response: Response; tokenSent: boolean }> {
    const headers = new Headers({ Accept: 'application/json' });
    const token = opts.auth === false ? null : options.getToken();
    if (token) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    if (opts.bookingSession && sessionId) {
      headers.set(BOOKING_SESSION_HEADER, sessionId);
    }

    let body: BodyInit | undefined;
    if (opts.formData) {
      body = opts.formData;
    } else if (opts.body !== undefined) {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(opts.body);
    }

    let response: Response;
    try {
      response = await fetchImpl(buildUrl(path, opts.query), {
        method: opts.method ?? 'GET',
        headers,
        body,
        signal: opts.signal,
        keepalive: opts.keepalive,
      });
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw error;
      }
      throw new ApiError({
        status: 0,
        code: 'NETWORK_ERROR',
        message: 'Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.',
      });
    }

    if (opts.bookingSession) {
      const issued = response.headers.get(BOOKING_SESSION_HEADER);
      if (issued) {
        sessions.set(issued);
      }
    }

    return { response, tokenSent: token !== null };
  }

  async function withBookingSession<R>(run: (sessionId: string | null) => Promise<R>): Promise<R> {
    for (;;) {
      const known = sessions.get();
      if (known) {
        return run(known);
      }
      if (sessionGate) {
        await sessionGate;
        continue;
      }
      let release!: () => void;
      sessionGate = new Promise<void>((resolve) => {
        release = resolve;
      });
      try {
        return await run(null);
      } finally {
        sessionGate = null;
        release();
      }
    }
  }

  async function execute(path: string, opts: RequestOptions): Promise<Response> {
    const attempt = async (sessionId: string | null, retried: boolean): Promise<Response> => {
      const { response, tokenSent } = await send(path, opts, sessionId);
      if (response.ok) {
        return response;
      }

      const error = await errorFrom(response);

      // Zapamiętany identyfikator sesji odrzucony przez serwer (np. zmiana formatu):
      // zapominamy go i ponawiamy RAZ bez nagłówka — serwer wyda nowy.
      if (opts.bookingSession && error.code === 'INVALID_SESSION_ID' && sessionId !== null && !retried) {
        sessions.clear();
        return attempt(null, true);
      }

      if (error.status === 401 && error.code === 'UNAUTHENTICATED' && tokenSent) {
        options.onUnauthorized();
      }

      throw error;
    };

    return opts.bookingSession
      ? withBookingSession((sessionId) => attempt(sessionId, false))
      : attempt(null, false);
  }

  return {
    async request<T>(path: string, opts: RequestOptions = {}): Promise<ApiResponse<T>> {
      const response = await execute(path, opts);
      if (response.status === 204) {
        return { status: 204, body: null as T, headers: response.headers };
      }
      let body: T;
      try {
        body = (await response.json()) as T;
      } catch {
        throw new ApiError({ status: response.status, code: 'INVALID_RESPONSE', message: 'Nieoczekiwana odpowiedź serwera.' });
      }
      return { status: response.status, body, headers: response.headers };
    },

    async requestBlob(path: string, opts: RequestOptions = {}): Promise<BlobResponse> {
      const response = await execute(path, opts);
      return {
        blob: await response.blob(),
        filename: filenameFromDisposition(response.headers.get('Content-Disposition')),
        headers: response.headers,
      };
    },
  };
}
