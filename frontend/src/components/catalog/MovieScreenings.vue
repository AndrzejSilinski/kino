<script setup lang="ts">
/*
 * Karta filmu z godzinami seansów (wymóg 3.1).
 * Seans do kupienia = link do planu sali. Wyprzedany albo rozpoczęty = NIE link: <span> z
 * aria-disabled, wyszarzony, przekreślona godzina i tekst stanu (rozróżnienie nie tylko kolorem).
 */
import { RouterLink } from 'vue-router';
import MoviePoster from './MoviePoster.vue';
import { formatDuration, timeOf } from '@/lib/datetime';
import { isOpenForBooking, showtimeState, showtimeStatusLabel, type MovieRepertoire } from '@/lib/repertoire';

defineProps<{ group: MovieRepertoire }>();
</script>

<template>
  <article class="movie-card">
    <MoviePoster :url="group.movie.poster_url" :title="group.movie.title" />
    <div class="movie-body">
      <h2 class="movie-title">{{ group.movie.title }}</h2>
      <p class="movie-meta">
        <span class="age-rating" :aria-label="`Kategoria wiekowa: ${group.movie.age_rating}`">{{ group.movie.age_rating }}</span>
        {{ formatDuration(group.movie.duration_minutes) }}
      </p>
      <ul class="showtimes">
        <li v-for="screening in group.screenings" :key="screening.id">
          <RouterLink
            v-if="isOpenForBooking(screening)"
            :to="{ name: 'screening-seats', params: { id: screening.id } }"
            class="showtime"
            :class="`is-${showtimeState(screening)}`"
            :data-test="`showtime-${screening.id}`"
          >
            <span class="showtime-time">{{ timeOf(screening.starts_at) }}</span>
            <span class="showtime-meta">{{ screening.projection_type_label }} · {{ screening.language_version_label }} · {{ screening.hall.name }}</span>
            <span class="showtime-status">{{ showtimeStatusLabel(screening) }}</span>
          </RouterLink>
          <span
            v-else
            class="showtime is-disabled"
            :class="`is-${showtimeState(screening)}`"
            aria-disabled="true"
            :data-test="`showtime-${screening.id}`"
          >
            <span class="showtime-time"><s>{{ timeOf(screening.starts_at) }}</s></span>
            <span class="showtime-meta">{{ screening.projection_type_label }} · {{ screening.language_version_label }} · {{ screening.hall.name }}</span>
            <span class="showtime-status">{{ showtimeStatusLabel(screening) }}</span>
          </span>
        </li>
      </ul>
    </div>
  </article>
</template>
