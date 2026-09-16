<script setup lang="ts">
/*
 * Seans i (od bloku F) interaktywny plan sali. Na razie: szczegóły seansu z GET /screenings/{id},
 * żeby link z repertuaru prowadził do działającego ekranu.
 */
import { computed, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { catalogApi } from '@/api/client';
import type { ScreeningDetails } from '@/api/types';
import { useLatestRequest } from '@/composables/useLatestRequest';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';

const route = useRoute();
const request = useLatestRequest<ScreeningDetails>();
const id = computed(() => Number(route.params.id));

watch(id, (value) => {
  if (Number.isInteger(value) && value > 0) {
    void request.run((signal) => catalogApi.screening(value, signal));
  }
}, { immediate: true });
</script>

<template>
  <section class="stack">
    <p v-if="request.loading.value" role="status">Wczytywanie seansu…</p>
    <p v-else-if="request.errorMessage.value" role="alert" class="alert">{{ request.errorMessage.value }}</p>
    <template v-else-if="request.data.value">
      <h1>{{ request.data.value.movie.title }}</h1>
      <p>
        {{ formatDayLabel(dateOf(request.data.value.starts_at)) }}, godz. {{ timeOf(request.data.value.starts_at) }}
        · {{ request.data.value.hall.cinema.name }}, {{ request.data.value.hall.name }}
        · {{ request.data.value.projection_type_label }}, {{ request.data.value.language_version_label }}
      </p>
      <p class="notice">Wybór miejsc na planie sali pojawi się w kolejnym kroku budowy aplikacji.</p>
      <p><RouterLink :to="{ name: 'repertoire', params: { slug: request.data.value.hall.cinema.slug }, query: { date: dateOf(request.data.value.starts_at) } }">Wróć do repertuaru</RouterLink></p>
    </template>
  </section>
</template>
