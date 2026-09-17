import { describe, expect, it } from 'vitest';

/**
 * Strażnik źródeł (blok M): v-html wstawia HTML bez escapowania, więc wolno go użyć tylko dla
 * treści oczyszczonej na serwerze (body_html artykułu, decyzja 183). Nowe użycie gdziekolwiek
 * indziej ma zatrzymać testy i wymusić świadomą decyzję, a nie przejść niezauważone w review.
 */
const ALLOWED = ['/src/views/ArticleView.vue'];

const sources = import.meta.glob('/src/**/*.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;

describe('v-html tylko dla treści oczyszczonej na serwerze', () => {
  it('pliki .vue są wczytane (strażnik nie przechodzi na pustej liście)', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(20);
  });

  it('v-html występuje wyłącznie w dozwolonych komponentach', () => {
    const offenders = Object.entries(sources).filter(([path, code]) => /\bv-html\b/.test(code) && !ALLOWED.includes(path)).map(([path]) => path);

    expect(offenders).toEqual([]);
    expect(/\bv-html="article\.body_html"/.test(sources['/src/views/ArticleView.vue'] ?? '')).toBe(true);
  });
});
