<script setup lang="ts">
/*
 * Stan aktualizacji na żywo (Etap 8, blok G). Przy braku połączenia nie odpytujemy serwera co sekundę:
 * klient widzi, że plan może być nieaktualny, i może go odświeżyć sam. Po powrocie połączenia
 * seatSync pobiera migawkę automatycznie. Kliknięcie miejsca i tak idzie do serwera, więc nieaktualny
 * plan nie sprzeda zajętego miejsca — najwyżej pokaże 409.
 */
import type { SyncStatus } from '@/realtime/seatSync';

defineProps<{ status: SyncStatus; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
</script>

<template>
  <p v-if="status === 'live'" class="realtime-status is-live" data-test="realtime-live">
    <span aria-hidden="true">●</span> Plan sali aktualizuje się na żywo
  </p>
  <p v-else-if="status === 'connecting'" class="realtime-status" role="status">Łączenie z aktualizacjami na żywo…</p>
  <div v-else class="realtime-banner" role="status" data-test="realtime-offline">
    <span>
      {{ status === 'offline'
        ? 'Brak połączenia na żywo — zmiany innych klientów mogą nie być widoczne.'
        : 'Aktualizacje na żywo są chwilowo niedostępne — plan może być nieaktualny.' }}
    </span>
    <button type="button" :disabled="refreshing" @click="emit('refresh')">
      {{ refreshing ? 'Odświeżanie…' : 'Odśwież plan' }}
    </button>
  </div>
</template>
