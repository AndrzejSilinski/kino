<script setup lang="ts">
/*
 * Aktualności i premiery (Etap 8, blok M): lista artykułów z GET /api/v1/articles.
 * Filtr rodzaju i numer strony w adresie (?type=premiere&page=2) — jak historia zakupów:
 * odświeżenie, wstecz i udostępniony link pokazują to samo. Szybkie przełączanie filtrów
 * rozstrzyga "najnowsza odpowiedź wygrywa" (useLatestRequest).
 */
import { computed, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { catalogApi } from '@/api/client';
import type { ArticleListItem, ArticleType, Paginated } from '@/api/types';
import { useLatestRequest } from '@/composables/useLatestRequest';
import { dateOf, formatDayLabel } from '@/lib/datetime';

const route = useRoute();
const router = useRouter();
const request = useLatestRequest<Paginated<ArticleListItem>>();

const FILTERS: { type: ArticleType | null; label: string }[] = [
  { type: null, label: 'Wszystko' },
  { type: 'news', label: 'Aktualności' },
  { type: 'premiere', label: 'Premiery' },
];

const type = computed<ArticleType | null>(() => (route.query.type === 'news' || route.query.type === 'premiere' ? route.query.type : null));
const page = computed(() => {
  const value = Number.parseInt(String(route.query.page ?? '1'), 10);
  return Number.isInteger(value) && value > 0 ? value : 1;
});
const articles = computed(() => request.data.value?.data ?? []);
const meta = computed(() => request.data.value?.meta ?? null);

function show(next: { type?: ArticleType | null; page?: number }): void {
  const nextType = next.type === undefined ? type.value : next.type;
  const nextPage = next.page ?? 1;
  void router.push({ query: { ...(nextType ? { type: nextType } : {}), ...(nextPage > 1 ? { page: String(nextPage) } : {}) } });
}

function load(): void {
  void request.run((signal) => catalogApi.articles(type.value, page.value, signal));
}

watch([type, page], load, { immediate: true });
</script>

<template>
  <section class="stack" aria-labelledby="articles-heading">
    <h1 id="articles-heading">Aktualności i premiery</h1>

    <div class="account-nav" role="group" aria-label="Rodzaj artykułów">
      <button
        v-for="filter in FILTERS"
        :key="filter.label"
        type="button"
        class="link-button"
        :class="{ 'is-active': type === filter.type }"
        :aria-pressed="type === filter.type"
        :data-test="`filter-${filter.type ?? 'all'}`"
        @click="show({ type: filter.type })"
      >{{ filter.label }}</button>
    </div>

    <p v-if="request.loading.value && !request.data.value" role="status">Wczytywanie artykułów…</p>
    <div v-else-if="request.errorMessage.value" role="alert" class="stack">
      <p class="alert">{{ request.errorMessage.value }}</p>
      <button type="button" @click="load">Spróbuj ponownie</button>
    </div>
    <p v-else-if="articles.length === 0" data-test="articles-empty">Nie ma jeszcze opublikowanych artykułów{{ type === 'premiere' ? ' o premierach' : '' }}.</p>

    <template v-else>
      <ul class="article-list">
        <li v-for="article in articles" :key="article.slug" class="article-card" :data-test="`article-${article.slug}`">
          <p class="field-hint">
            {{ article.type_label }}<template v-if="article.published_at"> · {{ formatDayLabel(dateOf(article.published_at)) }}</template>
          </p>
          <h2><RouterLink :to="{ name: 'article', params: { slug: article.slug } }">{{ article.title }}</RouterLink></h2>
          <p v-if="article.excerpt">{{ article.excerpt }}</p>
          <p v-if="article.movie" class="field-hint">Film: {{ article.movie.title }}</p>
        </li>
      </ul>

      <nav v-if="meta && meta.last_page > 1" aria-label="Strony artykułów" class="cart-actions">
        <button type="button" class="link-button" :disabled="page <= 1 || request.loading.value" data-test="prev-page" @click="show({ page: page - 1 })">← Nowsze</button>
        <span>Strona {{ meta.current_page }} z {{ meta.last_page }}</span>
        <button type="button" class="link-button" :disabled="page >= meta.last_page || request.loading.value" data-test="next-page" @click="show({ page: page + 1 })">Starsze →</button>
      </nav>
    </template>
  </section>
</template>
