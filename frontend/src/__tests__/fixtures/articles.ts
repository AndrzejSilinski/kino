import type { Article, ArticleListItem, Paginated } from '@/api/types';

export function articleItem(overrides: Partial<ArticleListItem> = {}): ArticleListItem {
  return {
    slug: 'premiera-diuny',
    type: 'premiere',
    type_label: 'Premiera',
    title: 'Premiera „Diuny” w naszych kinach',
    excerpt: 'Pokazy przedpremierowe w piątek.',
    published_at: '2026-09-15T10:00:00+02:00',
    movie: { slug: 'diuna', title: 'Diuna' },
    ...overrides,
  };
}

export function article(overrides: Partial<Article> = {}): Article {
  return { ...articleItem(), body_html: '<p>Treść <strong>artykułu</strong>.</p>', ...overrides };
}

export function articlesPage(items: ArticleListItem[], current = 1, last = 1): Paginated<ArticleListItem> {
  return { data: items, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: current, last_page: last, per_page: 12, total: items.length } };
}
