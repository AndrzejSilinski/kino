<script setup lang="ts">
/*
 * Wybór miejsc na seansie (wymóg 3.2). Logika blokowania jest w store'ach (cart, seatMap)
 * i w czystych modułach (seatState, seatLayout) — widok tylko łączy je z komponentami.
 */
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { loadClientConfig } from '@/api/clientConfig';
import CartPanel from '@/components/seats/CartPanel.vue';
import CountdownTimer from '@/components/seats/CountdownTimer.vue';
import SeatLegend from '@/components/seats/SeatLegend.vue';
import SeatMap from '@/components/seats/SeatMap.vue';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';
import { buildSeatLayout } from '@/lib/seatLayout';
import { DEFAULT_MAX_SEATS, useCartStore } from '@/stores/cart';
import { useSeatMapStore } from '@/stores/seatMap';

const route = useRoute();
const seatMap = useSeatMapStore();
const cart = useCartStore();

const id = computed(() => Number(route.params.id));
const screening = computed(() => seatMap.screening);
const layout = computed(() => (screening.value ? buildSeatLayout(seatMap.seats, screening.value.hall.grid) : null));

async function start(screeningId: number): Promise<void> {
  const maxSeats = await loadClientConfig().then((config) => config.booking.max_seats_per_session).catch(() => DEFAULT_MAX_SEATS);
  await cart.start(screeningId, maxSeats);
}

function onVisibility(): void {
  if (document.visibilityState === 'visible') {
    void cart.resync();
  }
}

watch(id, (value) => {
  if (Number.isInteger(value) && value > 0) {
    void start(value);
  }
}, { immediate: true });

onMounted(() => document.addEventListener('visibilitychange', onVisibility));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibility));
</script>

<template>
  <section class="stack seat-selection">
    <p v-if="seatMap.loading && !screening" role="status">Wczytywanie planu sali…</p>
    <div v-else-if="seatMap.errorMessage && !screening" role="alert" class="stack">
      <p class="alert">{{ seatMap.errorMessage }}</p>
      <button v-if="!seatMap.notFound" type="button" @click="start(id)">Spróbuj ponownie</button>
    </div>

    <template v-else-if="screening && layout">
      <header class="stack">
        <h1>{{ screening.movie.title }}</h1>
        <p>
          {{ formatDayLabel(dateOf(screening.starts_at)) }}, godz. {{ timeOf(screening.starts_at) }}
          · {{ screening.hall.cinema.name }}, {{ screening.hall.name }}
          · {{ screening.projection_type_label }}, {{ screening.language_version_label }}
        </p>
        <p>
          <RouterLink :to="{ name: 'repertoire', params: { slug: screening.hall.cinema.slug }, query: { date: dateOf(screening.starts_at) } }">
            Wróć do repertuaru
          </RouterLink>
        </p>
      </header>

      <p v-if="cart.closed" role="alert" class="alert">Sprzedaż na ten seans jest zamknięta.</p>
      <div v-if="cart.notice" :role="cart.notice.tone === 'error' ? 'alert' : 'status'" class="notice" :class="`notice-${cart.notice.tone}`" data-test="cart-notice">
        <span>{{ cart.notice.text }}</span>
        <button type="button" class="link-button" aria-label="Zamknij komunikat" @click="cart.dismissNotice">✕</button>
      </div>

      <div class="seat-selection-layout">
        <div class="stack">
          <SeatMap :layout="layout" :own-seat-ids="cart.ownSeatIds" :pending-seat-ids="cart.pending" :disabled="cart.closed" @toggle="cart.toggle" />
          <SeatLegend />
        </div>
        <div class="stack seat-selection-side">
          <CountdownTimer :deadline="cart.deadline" @expire="cart.onExpired" />
          <CartPanel :cart="cart.cart" :max-seats="cart.maxSeats" :busy="cart.pending.size > 0" @clear="cart.clear">
            <template #checkout>
              <button type="button" disabled title="Podsumowanie i płatność pojawią się w kolejnym kroku budowy aplikacji">Przejdź do podsumowania</button>
            </template>
          </CartPanel>
        </div>
      </div>
    </template>
  </section>
</template>
