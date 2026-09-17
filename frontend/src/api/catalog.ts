/*
 * Katalog: kina, dni z seansami, repertuar dnia, szczegóły seansu (API z Etapów 3 i 7).
 * Kształty z CinemaController, ScreeningController, ScreeningListItemResource i ScreeningResource.
 */
import type { HttpClient } from './http';
import type { Article, ArticleListItem, ArticleType, Cinema, CinemaGroup, Envelope, Paginated, ScreeningDate, ScreeningDetails, ScreeningListItem } from './types';

/** Rozmiar strony listy artykułów (serwer przyjmuje per_page do 50). */
export const ARTICLES_PER_PAGE = 12;

/** Serwer przyjmuje per_page do 100 (ScreeningDayRequest). */
const SCREENINGS_PER_PAGE = 100;
/** Bezpiecznik pętli stronicowania — dzień z ponad 1000 seansów w jednym kinie to błąd danych. */
const MAX_PAGES = 10;

export interface ScreeningDatesResponse {
  dates: ScreeningDate[];
  today: string;
  timezone: string;
}

export interface CatalogApi {
  cinemas(signal?: AbortSignal): Promise<CinemaGroup[]>;
  cinema(slug: string, signal?: AbortSignal): Promise<Cinema>;
  screeningDates(slug: string, signal?: AbortSignal): Promise<ScreeningDatesResponse>;
  screeningsForDay(slug: string, date: string, signal?: AbortSignal): Promise<ScreeningListItem[]>;
  screening(id: number, signal?: AbortSignal): Promise<ScreeningDetails>;
  /** Aktualności i premiery (blok M): opublikowane, od najnowszych; bez type — oba rodzaje. */
  articles(type: ArticleType | null, page: number, signal?: AbortSignal): Promise<Paginated<ArticleListItem>>;
  article(slug: string, signal?: AbortSignal): Promise<Article>;
}

export function createCatalogApi(http: HttpClient): CatalogApi {
  return {
    async cinemas(signal) {
      return (await http.request<Envelope<CinemaGroup[]>>('/cinemas', { signal, auth: false })).body.data;
    },
    async cinema(slug, signal) {
      return (await http.request<Envelope<Cinema>>(`/cinemas/${encodeURIComponent(slug)}`, { signal, auth: false })).body.data;
    },
    async screeningDates(slug, signal) {
      const { body } = await http.request<{ data: ScreeningDate[]; meta: { today: string; timezone: string } }>(
        `/cinemas/${encodeURIComponent(slug)}/screening-dates`, { signal, auth: false });
      return { dates: body.data, today: body.meta.today, timezone: body.meta.timezone };
    },
    async screeningsForDay(slug, date, signal) {
      const items: ScreeningListItem[] = [];
      for (let page = 1; page <= MAX_PAGES; page++) {
        const { body } = await http.request<Paginated<ScreeningListItem>>(`/cinemas/${encodeURIComponent(slug)}/screenings`, {
          query: { date, per_page: SCREENINGS_PER_PAGE, page },
          signal,
          auth: false,
        });
        items.push(...body.data);
        if (body.meta.current_page >= body.meta.last_page) {
          break;
        }
      }
      return items;
    },
    async articles(type, page, signal) {
      return (await http.request<Paginated<ArticleListItem>>('/articles', {
        query: { type, page, per_page: ARTICLES_PER_PAGE },
        signal,
        auth: false,
      })).body;
    },
    async article(slug, signal) {
      return (await http.request<Envelope<Article>>(`/articles/${encodeURIComponent(slug)}`, { signal, auth: false })).body.data;
    },
    async screening(id, signal) {
      return (await http.request<Envelope<ScreeningDetails>>(`/screenings/${id}`, { signal, auth: false })).body.data;
    },
  };
}
