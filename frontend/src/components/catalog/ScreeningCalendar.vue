<script setup lang="ts">
/*
 * Kalendarz dni z repertuarem (wymóg 3.1): pasek 14 dni od dziś w strefie kina.
 * Dni z seansami są przyciskami z liczbą seansów; dni bez seansów są widoczne, ale wyłączone.
 * Na telefonie pasek przewija się poziomo. Stan wybrany: aria-pressed + obramowanie (nie tylko kolor).
 */
import type { CalendarDay } from '@/lib/calendar';

defineProps<{ days: CalendarDay[]; selected: string | null }>();
const emit = defineEmits<{ select: [date: string] }>();

function screeningsLabel(count: number): string {
  if (count === 1) return '1 seans';
  const lastDigit = count % 10;
  const lastTwo = count % 100;
  return lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${count} seanse` : `${count} seansów`;
}
</script>

<template>
  <nav class="calendar" aria-label="Wybór dnia">
    <ul class="calendar-days">
      <li v-for="day in days" :key="day.date">
        <button
          type="button"
          class="calendar-day"
          :class="{ 'is-selected': day.date === selected, 'is-empty': !day.hasScreenings }"
          :disabled="!day.hasScreenings"
          :aria-pressed="day.date === selected"
          :aria-label="`${day.isToday ? 'dziś, ' : ''}${day.label}: ${day.hasScreenings ? screeningsLabel(day.screenings) : 'brak seansów'}`"
          :data-date="day.date"
          @click="emit('select', day.date)"
        >
          <span class="calendar-weekday">{{ day.isToday ? 'dziś' : day.weekday }}</span>
          <span class="calendar-number">{{ day.day }}</span>
          <span class="calendar-count" aria-hidden="true">{{ day.hasScreenings ? day.screenings : '–' }}</span>
        </button>
      </li>
    </ul>
  </nav>
</template>
