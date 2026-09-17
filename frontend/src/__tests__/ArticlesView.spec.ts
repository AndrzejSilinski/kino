import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import { article, articleItem, articlesPage } from './fixtures/articles';

const catalog = vi.hoisted(() => ({ articles: vi.fn(), article: vi.fn() }));
vi.mock('@/api/client', () => ({ catalogApi: catalog }));

const { routes } = await import('@/router');
const { default: ArticlesView } = await import('@/views/ArticlesView.vue');
const { default: ArticleView } = await import('@/views/ArticleView.vue');

async function mountAt(component: typeof ArticlesView, path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  const wrapper = mount(component, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('aktualności i premiery', () => {
  beforeEach(() => vi.resetAllMocks());

  it('lista z rodzajem, datą i odnośnikiem; filtr i strona trafiają do adresu', async () => {
    catalog.articles.mockResolvedValue(articlesPage([articleItem()], 1, 2));
    const { wrapper, router } = await mountAt(ArticlesView, '/news');

    expect(catalog.articles).toHaveBeenCalledWith(null, 1, expect.any(AbortSignal));
    const card = wrapper.get('[data-test="article-premiera-diuny"]');
    expect(card.text()).toContain('Premiera');
    expect(card.get('a').attributes('href')).toBe('/news/premiera-diuny');
    expect(wrapper.get('[data-test="filter-all"]').attributes('aria-pressed')).toBe('true');

    await wrapper.get('[data-test="filter-premiere"]').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query).toEqual({ type: 'premiere' });
    expect(catalog.articles).toHaveBeenLastCalledWith('premiere', 1, expect.any(AbortSignal));

    await wrapper.get('[data-test="next-page"]').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query).toEqual({ type: 'premiere', page: '2' });
  });

  it('nieznany rodzaj w adresie traktowany jak "wszystko"; pusta lista ma komunikat', async () => {
    catalog.articles.mockResolvedValue(articlesPage([]));
    const { wrapper } = await mountAt(ArticlesView, '/news?type=<script>');

    expect(catalog.articles).toHaveBeenCalledWith(null, 1, expect.any(AbortSignal));
    expect(wrapper.get('[data-test="articles-empty"]').text()).toContain('Nie ma jeszcze opublikowanych artykułów');
  });

  it('artykuł: treść HTML z serwera wyrenderowana w przeznaczonym kontenerze', async () => {
    catalog.article.mockResolvedValue(article());
    const { wrapper } = await mountAt(ArticleView, '/news/premiera-diuny');

    expect(catalog.article).toHaveBeenCalledWith('premiera-diuny', expect.any(AbortSignal));
    expect(wrapper.get('h1').text()).toBe('Premiera „Diuny” w naszych kinach');
    expect(wrapper.get('[data-test="article-body"] strong').text()).toBe('artykułu');
  });

  it('artykuł nieopublikowany albo nieistniejący (404): komunikat zamiast treści', async () => {
    catalog.article.mockRejectedValue(new ApiError({ status: 404, code: 'RESOURCE_NOT_FOUND', message: 'x' }));
    const { wrapper } = await mountAt(ArticleView, '/news/nie-ma');

    expect(wrapper.get('h1').text()).toBe('Nie znaleziono artykułu');
    expect(wrapper.find('[data-test="article-body"]').exists()).toBe(false);
  });
});
