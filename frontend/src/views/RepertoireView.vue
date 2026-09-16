<script setup lang="ts">
/*
 * Repertuar kina (wymóg 3.1): kalendarz dni i karty filmów z godzinami seansów.
 * Wybrany dzień jest w adresie (?date=RRRR-MM-DD): odświeżenie i udostępniony link pokazują ten sam dzień.
 * Zmiana dnia to nowe żądanie; wynik starszego, wolniejszego żądania jest ignorowany (useLatestRequest).
 */
import { computed, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { catalogApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Cinema, ScreeningListItem } from '@/api/types';
import ScreeningCalendar from '@/components/catalog/ScreeningCalendar.vue';
import MovieScreenings from '@/components/catalog/MovieScreenings.vue';
import { useLatestRequest } from '@/composables/useLatestRequest';
import { buildCalendar, pickDate, type CalendarDay } from '@/lib/calendar';
import { formatDayLabel } from '@/lib/datetime';
import { groupByMovie } from '@/lib/repertoire';
import { useCinemaStore } from '@/stores/cinema';

const route = useRoute();
const router = useRouter();
const cinemas = useCinemaStore();

const header = useLatestRequest<{ cinema: Cinema; days: CalendarDay[] }>();
const day = useLatestRequest<ScreeningListItem[]>();

const slug = computed(() => String(route.params.slug ?? ''));
const days = computed(() => header.data.value?.days ?? []);
const selectedDate = computed(() => pickDate(route.query.date, days.value));
const movies = computed(() => groupByMovie(day.data.value ?? []));
const notFound = computed(() => isApiError(header.error.value) && header.error.value.status === 404);

async function loadHeader(): Promise<void> {
  header.reset();
  day.reset();
  await header.run(async (signal) => {
    const [cinema, dates] = await Promise.all([catalogApi.cinema(slug.value, signal), catalogApi.screeningDates(slug.value, signal)]);
    return { cinema, days: buildCalendar(dates.today, dates.dates) };
  });
  if (header.data.value) {
    cinemas.select(slug.value);
  } else if (notFound.value && cinemas.selectedSlug === slug.value) {
    cinemas.forget();
  }
}

function selectDate(date: string): void {
  void router.replace({ query: { ...route.query, date } });
}

function loadDay(): void {
  const data = header.data.value;
  const date = selectedDate.value;
  if (data && date) {
    void day.run((signal) => catalogApi.screeningsForDay(data.cinema.slug, date, signal));
  }
}

watch(slug, loadHeader, { immediate: true });
// Repertuar dnia ładujemy, gdy znamy kino i kalendarz, oraz po każdej zmianie dnia.
watch([() => header.data.value, selectedDate], loadDay);
</script>

<template>
  <section class="stack">
    <p v-if="header.loading.value && !header.data.value" role="status">Wczytywanie repertuaru…</p>

    <div v-else-if="notFound" class="stack" role="alert">
      <h1>Kino niedostępne</h1>
      <p>To kino nie prowadzi już sprzedaży albo adres jest nieprawidłowy.</p>
      <p><RouterLink :to="{ name: 'home', query: { change: '1' } }">Wybierz inne kino</RouterLink></p>
    </div>

    <div v-else-if="header.errorMessage.value" class="stack" role="alert">
      <p class="alert">{{ header.errorMessage.value }}</p>
      <button type="button" @click="loadHeader">Spróbuj ponownie</button>
    </div>

    <template v-else-if="header.data.value">
      <header class="cinema-header">
        <div>
          <h1>{{ header.data.value.cinema.name }}</h1>
          <p class="cinema-address">{{ header.data.value.cinema.city }}, {{ header.data.value.cinema.address }}</p>
        </div>
        <RouterLink :to="{ name: 'home', query: { change: '1' } }">Zmień kino</RouterLink>
      </header>

      <ScreeningCalendar :days="days" :selected="selectedDate" @select="selectDate" />

      <h2 v-if="selectedDate" class="day-heading">Repertuar: {{ formatDayLabel(selectedDate) }}</h2>

      <p v-if="days.every((d) => !d.hasScreenings)">W najbliższych dniach nie ma seansów w tym kinie.</p>
      <p v-else-if="day.loading.value" role="status">Wczytywanie seansów…</p>
      <div v-else-if="day.errorMessage.value" role="alert" class="stack">
        <p class="alert">{{ day.errorMessage.value }}</p>
        <button type="button" @click="loadDay">Spróbuj ponownie</button>
      </div>
      <p v-else-if="movies.length === 0">Brak seansów w wybranym dniu.</p>
      <div v-else class="movie-list" aria-live="polite">
        <MovieScreenings v-for="group in movies" :key="group.movie.id" :group="group" />
      </div>
    </template>
  </section>
</template>
