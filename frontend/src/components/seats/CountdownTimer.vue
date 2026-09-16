<script setup lang="ts">
/*
 * Czas do wygaśnięcia blokad — widoczny stale, gdy koszyk nie jest pusty (wymóg 3.2).
 * Tekst zmienia się co sekundę, ale czytnik ekranu ogłasza tylko progi (5 min, 1 min),
 * żeby nie zagłuszać klienta co sekundę.
 */
import { computed, toRef } from 'vue';
import { useCountdown, type Deadline } from '@/composables/useCountdown';

const props = defineProps<{ deadline: Deadline | null }>();
const emit = defineEmits<{ expire: [] }>();

const { remaining, label } = useCountdown(toRef(props, 'deadline'), { onExpire: () => emit('expire') });

const announcement = computed(() => {
  if (remaining.value === null) return '';
  if (remaining.value <= 60) return 'Została niecała minuta na dokończenie zakupu.';
  if (remaining.value <= 300) return 'Zostało mniej niż 5 minut na dokończenie zakupu.';
  return '';
});
</script>

<template>
  <div v-if="remaining !== null" class="countdown" :class="{ 'is-urgent': remaining <= 60 }">
    <span>Miejsca zarezerwowane jeszcze przez</span>
    <strong role="timer" data-test="countdown">{{ label }}</strong>
    <span class="visually-hidden" aria-live="polite">{{ announcement }}</span>
  </div>
</template>
