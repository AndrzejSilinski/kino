<script setup lang="ts">
/*
 * Interaktywny plan sali (wymóg 3.2): widok z góry, ekran u góry, siatka CSS z prawdziwymi <button>.
 *
 * Dlaczego siatka CSS, a nie SVG czy canvas: przycisk daje za darmo fokus, obsługę klawiatury,
 * disabled i aria-*; w SVG trzeba to składać ręcznie, canvas jest dla czytnika ekranu pusty.
 * Rząd to <div role="group" style="display: contents">: czytnik ogłasza "Rząd F", a układ zostaje siatką.
 * Bez role="grid": ten wzorzec ARIA obiecuje nawigację strzałkami, której tu nie ma — Tab po przyciskach
 * (zajęte miejsca są wyłączone, więc nie są przystankami tabulacji).
 * Stany rozróżnia kolor ORAZ znak i obramowanie (✓ wybrane, ✕ sprzedane, kreski zablokowane).
 * Na telefonie: przewijany kontener i przyciski powiększenia zamiast własnego pinch-to-zoom.
 */
import { computed, ref } from 'vue';
import type { SeatLayout } from '@/lib/seatLayout';
import { presentSeat, seatAriaLabel } from '@/lib/seatState';

const props = defineProps<{
  layout: SeatLayout;
  ownSeatIds: ReadonlySet<number>;
  pendingSeatIds: ReadonlySet<number>;
  disabled?: boolean;
}>();
const emit = defineEmits<{ toggle: [seatId: number] }>();

const ZOOM_STEPS = [1.75, 2.25, 2.75, 3.25];
const zoom = ref(1);
const seatSize = computed(() => `${ZOOM_STEPS[zoom.value]}rem`);

const GLYPHS: Record<string, string> = { selected: '✓', sold: '✕', unavailable: '' };

const rows = computed(() => props.layout.rows.map((row) => ({
  ...row,
  seats: row.seats.map((placed) => {
    const presentation = presentSeat(placed.seat, props.ownSeatIds, props.pendingSeatIds);
    return {
      ...placed,
      presentation,
      label: seatAriaLabel(placed.seat, presentation),
      glyph: presentation.busy ? '…' : placed.seat.type === 'accessible' && presentation.view === 'free' ? '♿' : GLYPHS[presentation.view] ?? '',
    };
  }),
})));
</script>

<template>
  <div class="seat-map">
    <div class="seat-map-toolbar">
      <span class="seat-map-hint">Kliknij wolne miejsce, żeby je zarezerwować.</span>
      <div class="zoom" role="group" aria-label="Powiększenie planu sali">
        <button type="button" class="zoom-button" :disabled="zoom === 0" aria-label="Pomniejsz plan" @click="zoom--">−</button>
        <button type="button" class="zoom-button" :disabled="zoom === ZOOM_STEPS.length - 1" aria-label="Powiększ plan" @click="zoom++">+</button>
      </div>
    </div>

    <div class="seat-map-scroll">
      <div class="screen" aria-hidden="true">EKRAN</div>
      <div
        class="seat-grid"
        role="group"
        aria-label="Plan sali"
        :style="{ '--seat-size': seatSize, gridTemplateColumns: `auto repeat(${layout.columns}, var(--seat-size)) auto` }"
      >
        <div v-for="row in rows" :key="row.y" role="group" :aria-label="`Rząd ${row.label}`" class="seat-row">
          <span class="row-label" aria-hidden="true" :style="{ gridRow: row.y, gridColumn: 1 }">{{ row.label }}</span>
          <span
            v-for="item in row.seats"
            :key="item.seat.id"
            class="seat-cell"
            :style="{ gridRow: row.y, gridColumn: `${item.column} / span ${item.span}` }"
          >
            <button
              type="button"
              class="seat"
              :class="[`seat-${item.presentation.view}`, `seat-type-${item.seat.type}`, { 'seat-busy': item.presentation.busy }]"
              :data-seat-id="item.seat.id"
              :disabled="disabled || !item.presentation.actionable"
              :aria-pressed="item.presentation.view === 'selected'"
              :aria-busy="item.presentation.busy || undefined"
              :aria-label="item.label"
              :title="item.label"
              @click="emit('toggle', item.seat.id)"
            >
              <span aria-hidden="true">{{ item.glyph || item.seat.number }}</span>
            </button>
          </span>
          <span class="row-label" aria-hidden="true" :style="{ gridRow: row.y, gridColumn: layout.columns + 2 }">{{ row.label }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
