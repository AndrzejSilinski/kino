/*
 * Wybrane kino (Etap 8, blok E): lista kin pogrupowana po miastach i zapamiętanie wyboru.
 *
 * Wybór w localStorage (wymóg 3.1 "zapamiętanie wyboru użytkownika"): przetrwa zamknięcie
 * przeglądarki i dotyczy wszystkich kart. Zapisujemy tylko slug — dane kina zawsze z API,
 * bo adres czy nazwa mogą się zmienić w panelu.
 */
import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { catalogApi } from '@/api/client';
import type { Cinema, CinemaGroup } from '@/api/types';
import { readItem, removeItem, safeStorage, writeItem } from '@/lib/storage';

export const SELECTED_CINEMA_KEY = 'cinema.selected';
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** Odczyt bez Pinii — potrzebny strażnikowi trasy "/" jeszcze przed wyrenderowaniem widoku. */
export function readRememberedCinema(storage: Storage | null = safeStorage('local')): string | null {
  const slug = readItem(storage, SELECTED_CINEMA_KEY);
  return slug !== null && SLUG_PATTERN.test(slug) ? slug : null;
}

export const useCinemaStore = defineStore('cinema', () => {
  const storage = safeStorage('local');
  const groups = ref<CinemaGroup[]>([]);
  const selectedSlug = ref<string | null>(readRememberedCinema(storage));
  const loaded = ref(false);

  const allCinemas = computed<Cinema[]>(() => groups.value.flatMap((group) => group.cinemas));
  const selectedCinema = computed(() => allCinemas.value.find((cinema) => cinema.slug === selectedSlug.value) ?? null);

  async function loadCinemas(force = false): Promise<void> {
    if (loaded.value && !force) {
      return;
    }
    groups.value = await catalogApi.cinemas();
    loaded.value = true;
  }

  function select(slug: string): void {
    if (!SLUG_PATTERN.test(slug)) {
      return;
    }
    selectedSlug.value = slug;
    writeItem(storage, SELECTED_CINEMA_KEY, slug);
  }

  /** Zapamiętane kino zniknęło z oferty (404) — zapominamy je, żeby nie przekierowywać w pustkę. */
  function forget(): void {
    selectedSlug.value = null;
    removeItem(storage, SELECTED_CINEMA_KEY);
  }

  return { groups, selectedSlug, selectedCinema, allCinemas, loaded, loadCinemas, select, forget };
});
