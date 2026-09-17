<script setup lang="ts">
/*
 * Artykuł (Etap 8, blok M).
 *
 * v-html WYŁĄCZNIE tutaj i wyłącznie dla body_html z API: serwer renderuje Markdown z
 * html_input=strip i allow_unsafe_links=false (decyzja 183), więc surowy <script>, <iframe>,
 * atrybuty on* i linki javascript: nie przechodzą. Druga warstwa to CSP dokumentu SPA
 * (script-src bez 'unsafe-inline'): nawet przemycony skrypt inline by się nie wykonał.
 * Test source-guard pilnuje, żeby v-html nie pojawił się w innym komponencie.
 */
import { ref, shallowRef, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { catalogApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Article } from '@/api/types';
import { dateOf, formatDayLabel } from '@/lib/datetime';
import { messageFor } from '@/messages';

const route = useRoute();
const article = shallowRef<Article | null>(null);
const loadError = ref<string | null>(null);
const notFound = ref(false);
let controller: AbortController | null = null;

async function load(slug: string): Promise<void> {
  controller?.abort();
  controller = new AbortController();
  article.value = null;
  loadError.value = null;
  notFound.value = false;
  try {
    article.value = await catalogApi.article(slug, controller.signal);
    document.title = `${article.value.title} · Kino`;
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      return;
    }
    notFound.value = isApiError(error) && error.status === 404;
    loadError.value = notFound.value ? null : messageFor(error);
  }
}

watch(() => String(route.params.slug), (slug) => void load(slug), { immediate: true });
</script>

<template>
  <article class="stack narrow-text">
    <p><RouterLink :to="{ name: 'articles' }">← Wszystkie artykuły</RouterLink></p>
    <div v-if="notFound" role="alert" class="stack">
      <h1>Nie znaleziono artykułu</h1>
      <p>Artykuł nie istnieje albo nie jest już opublikowany.</p>
    </div>
    <p v-else-if="!article && !loadError" role="status">Wczytywanie artykułu…</p>
    <div v-else-if="!article" role="alert" class="stack">
      <p class="alert">{{ loadError }}</p>
      <button type="button" @click="load(String(route.params.slug))">Spróbuj ponownie</button>
    </div>
    <template v-else>
      <header class="stack">
        <p class="field-hint">
          {{ article.type_label }}<template v-if="article.published_at"> · {{ formatDayLabel(dateOf(article.published_at)) }}</template>
          <template v-if="article.movie"> · film: {{ article.movie.title }}</template>
        </p>
        <h1>{{ article.title }}</h1>
        <p v-if="article.excerpt" class="article-lead">{{ article.excerpt }}</p>
      </header>
      <div class="article-body" data-test="article-body" v-html="article.body_html" />
    </template>
  </article>
</template>
