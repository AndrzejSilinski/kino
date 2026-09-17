import { describe, expect, it, vi } from 'vitest';
import { createBookingSessionStore } from '@/api/bookingSession';
import { createCatalogApi } from '@/api/catalog';
import { createHttpClient } from '@/api/http';
import { article, articlesPage } from './fixtures/articles';

function setup(body: unknown) {
  const fetchMock = vi.fn<typeof fetch>(async () => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  const http = createHttpClient({ fetchImpl: fetchMock, getToken: () => 'token-testowy', bookingSession: createBookingSessionStore(null), onUnauthorized: vi.fn() });
  const sent = () => {
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    return { url: String(url), headers: new Headers((init as RequestInit).headers) };
  };
  return { api: createCatalogApi(http), sent };
}

describe('API artykułów', () => {
  it('lista: bez rodzaju nie wysyła pustego type; publiczna, więc bez tokenu', async () => {
    const { api, sent } = setup(articlesPage([]));

    await api.articles(null, 1);

    expect(sent().url).toBe('/api/v1/articles?page=1&per_page=12');
    expect(sent().headers.get('Authorization')).toBeNull();
  });

  it('lista premier na drugiej stronie i artykuł po slugu', async () => {
    const list = setup(articlesPage([]));
    await list.api.articles('premiere', 2);
    expect(list.sent().url).toBe('/api/v1/articles?type=premiere&page=2&per_page=12');

    const single = setup({ data: article() });
    expect((await single.api.article('premiera-diuny')).body_html).toContain('<strong>');
    expect(single.sent().url).toBe('/api/v1/articles/premiera-diuny');
  });
});
