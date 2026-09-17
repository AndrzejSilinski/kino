<script setup lang="ts">
/*
 * Historia zakupów (Etap 8, blok J, wymóg 3.4): rezerwacje od najnowszej, stronicowane.
 * Numer strony w adresie (?page=2) — odświeżenie i przycisk wstecz wracają w to samo miejsce.
 * Bilety i PDF są w szczegółach rezerwacji (/bookings/{reference}, blok H4).
 */
import { computed, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { bookingsApi } from '@/api/client';
import type { Booking, Paginated } from '@/api/types';
import { useLatestRequest } from '@/composables/useLatestRequest';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';

const route = useRoute();
const router = useRouter();
const request = useLatestRequest<Paginated<Booking>>();

const page = computed(() => {
  const value = Number.parseInt(String(route.query.page ?? '1'), 10);
  return Number.isInteger(value) && value > 0 ? value : 1;
});
const bookings = computed(() => request.data.value?.data ?? []);
const meta = computed(() => request.data.value?.meta ?? null);

function goTo(next: number): void {
  void router.push({ query: next > 1 ? { page: String(next) } : {} });
}

/** Stan zwrotu po anulowaniu przez kino (BookingResource.cancellation, decyzja 174: bez powodu). */
function refundText(booking: Booking): string | null {
  switch (booking.cancellation?.refund) {
    case 'pending':
      return 'Zwrot pieniędzy w toku';
    case 'refunded':
      return 'Pieniądze zwrócone';
    default:
      return null;
  }
}

watch(page, (value) => void request.run((signal) => bookingsApi.list(value, signal)), { immediate: true });
</script>

<template>
  <section class="stack" aria-labelledby="bookings-heading">
    <h2 id="bookings-heading">Rezerwacje</h2>
    <p v-if="request.loading.value && !request.data.value" role="status">Wczytywanie rezerwacji…</p>
    <div v-else-if="request.errorMessage.value" role="alert" class="stack">
      <p class="alert">{{ request.errorMessage.value }}</p>
      <button type="button" @click="request.run((signal) => bookingsApi.list(page, signal))">Spróbuj ponownie</button>
    </div>
    <div v-else-if="bookings.length === 0" class="stack" data-test="bookings-empty">
      <p>Nie masz jeszcze żadnych rezerwacji.</p>
      <RouterLink to="/">Wybierz seans</RouterLink>
    </div>

    <template v-else>
      <ul class="booking-list">
        <li v-for="booking in bookings" :key="booking.reference" class="booking-item" :data-test="`booking-${booking.reference}`">
          <div class="booking-item-main">
            <strong>{{ booking.screening?.movie.title ?? 'Rezerwacja' }}</strong>
            <span v-if="booking.screening">
              {{ formatDayLabel(dateOf(booking.screening.starts_at)) }}, godz. {{ timeOf(booking.screening.starts_at) }}
              · {{ booking.screening.hall.cinema.name }}
            </span>
            <span class="field-hint">
              {{ booking.status_label }} · {{ booking.total.formatted }}
              <template v-if="booking.tickets_count"> · biletów: {{ booking.tickets_count }}</template>
              <template v-if="refundText(booking)"> · {{ refundText(booking) }}</template>
            </span>
          </div>
          <RouterLink v-if="booking.status === 'pending'" :to="{ name: 'payment-result', params: { reference: booking.reference } }">Stan płatności</RouterLink>
          <RouterLink v-else :to="{ name: 'booking', params: { reference: booking.reference } }">
            {{ booking.status === 'paid' ? 'Bilety' : 'Szczegóły' }}
          </RouterLink>
        </li>
      </ul>

      <nav v-if="meta && meta.last_page > 1" aria-label="Strony rezerwacji" class="cart-actions">
        <button type="button" class="link-button" :disabled="page <= 1 || request.loading.value" data-test="prev-page" @click="goTo(page - 1)">← Nowsze</button>
        <span>Strona {{ meta.current_page }} z {{ meta.last_page }}</span>
        <button type="button" class="link-button" :disabled="page >= meta.last_page || request.loading.value" data-test="next-page" @click="goTo(page + 1)">Starsze →</button>
      </nav>
    </template>
  </section>
</template>
