<script setup lang="ts">
/*
 * Ekran startowy — w bloku E zamieni się w wybór kina.
 * Na razie pokazuje, że SPA rozmawia z API przez ten sam origin (nginx albo proxy Vite).
 */
import { onMounted, ref } from 'vue';
import { fetchClientConfig } from '@/api/clientConfig';

const status = ref<'loading' | 'ok' | 'error'>('loading');

onMounted(async () => {
  try {
    await fetchClientConfig();
    status.value = 'ok';
  } catch {
    status.value = 'error';
  }
});
</script>

<template>
  <section class="stack">
    <h1>Wybierz kino</h1>
    <p>Repertuar i wybór kina pojawią się w kolejnym kroku budowy aplikacji.</p>
    <p role="status" data-test="api-status">
      <template v-if="status === 'loading'">Łączenie z serwerem…</template>
      <template v-else-if="status === 'ok'">Połączenie z serwerem działa.</template>
      <template v-else>Serwer jest chwilowo niedostępny. Spróbuj odświeżyć stronę.</template>
    </p>
  </section>
</template>
