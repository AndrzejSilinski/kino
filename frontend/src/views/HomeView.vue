<script setup lang="ts">
/*
 * Wybór kina (wymóg 3.1): lista pogrupowana po miastach, wybór zapamiętany w localStorage.
 * Zapamiętane kino otwiera od razu repertuar (strażnik trasy "/"); "Zmień kino" prowadzi tu z ?change=1.
 */
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useCinemaStore } from '@/stores/cinema';
import { useLatestRequest } from '@/composables/useLatestRequest';

const cinemas = useCinemaStore();
const router = useRouter();
const request = useLatestRequest<void>();

function load(): Promise<void> {
  return request.run(() => cinemas.loadCinemas(true));
}

async function choose(slug: string): Promise<void> {
  cinemas.select(slug);
  await router.push({ name: 'repertoire', params: { slug } });
}

onMounted(load);
</script>

<template>
  <section class="stack">
    <h1>Wybierz kino</h1>

    <p v-if="request.loading.value && !cinemas.loaded" role="status">Wczytywanie listy kin…</p>
    <div v-else-if="request.errorMessage.value" role="alert" class="stack">
      <p class="alert">{{ request.errorMessage.value }}</p>
      <button type="button" @click="load">Spróbuj ponownie</button>
    </div>

    <template v-else>
      <section v-for="group in cinemas.groups" :key="group.city" class="city-group" :aria-labelledby="`city-${group.city}`">
        <h2 :id="`city-${group.city}`">{{ group.city }}</h2>
        <ul class="cinema-list">
          <li v-for="cinema in group.cinemas" :key="cinema.slug">
            <button
              type="button"
              class="cinema-choice"
              :aria-current="cinema.slug === cinemas.selectedSlug ? 'true' : undefined"
              :data-test="`cinema-${cinema.slug}`"
              @click="choose(cinema.slug)"
            >
              <span class="cinema-name">{{ cinema.name }}</span>
              <span class="cinema-address">{{ cinema.address }}</span>
              <span v-if="cinema.slug === cinemas.selectedSlug" class="cinema-badge">Twoje kino</span>
            </button>
          </li>
        </ul>
      </section>
      <p v-if="cinemas.loaded && cinemas.groups.length === 0">Brak aktywnych kin.</p>
    </template>
  </section>
</template>
