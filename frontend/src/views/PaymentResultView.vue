<script setup lang="ts">
/*
 * Wynik płatności (Etap 8, blok H3). Tu trafia klient po potwierdzeniu w formularzu i po powrocie
 * z metody z przekierowaniem (return_url Stripe'a).
 *
 * Stan pokazujemy WYŁĄCZNIE z serwera (GET /bookings/{reference}): parametry dopisane przez Stripe
 * do adresu usuwa strażnik trasy jeszcze przed wejściem (client_secret nie zostaje w historii), a jedyny
 * zachowany — redirect_status — służy tylko do lepszego komunikatu, gdy rezerwacja nadal czeka.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { bookingsApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Booking } from '@/api/types';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';
import { messageFor } from '@/messages';
import { getRealtimeConnection } from '@/realtime';
import { watchBooking, type BookingWatch, type BookingWatchStatus } from '@/realtime/bookingWatch';

const route = useRoute();
const reference = computed(() => String(route.params.reference));
const redirectFailed = computed(() => route.query.redirect_status === 'failed');

const booking = shallowRef<Booking | null>(null);
const watchStatus = ref<BookingWatchStatus>('waiting');
const loadError = ref<string | null>(null);
const notFound = ref(false);
let watcher: BookingWatch | null = null;

async function start(value: string): Promise<void> {
  watcher?.stop();
  booking.value = null;
  loadError.value = null;
  notFound.value = false;
  const connection = await getRealtimeConnection().catch(() => null);
  if (reference.value !== value) {
    return;
  }
  watcher = watchBooking({
    reference: value,
    connection,
    fetchBooking: () => bookingsApi.show(value),
    onBooking: (value) => {
      booking.value = value;
      loadError.value = null;
    },
    onStatus: (status) => {
      watchStatus.value = status;
    },
    onError: (error) => {
      if (isApiError(error) && (error.status === 403 || error.status === 404)) {
        notFound.value = true;
        watcher?.stop();
      } else if (!booking.value) {
        loadError.value = messageFor(error);
      }
    },
  });
  await watcher.start();
}

watch(reference, (value) => void start(value), { immediate: true });
onBeforeUnmount(() => watcher?.stop());

const screening = computed(() => booking.value?.screening ?? null);
</script>

<template>
  <section class="stack payment-result">
    <div v-if="notFound" role="alert" class="stack">
      <h1>Nie znaleziono rezerwacji</h1>
      <p>Ta rezerwacja nie istnieje albo należy do innego konta.</p>
      <RouterLink to="/">Wróć na stronę główną</RouterLink>
    </div>

    <p v-else-if="!booking && !loadError" role="status">Sprawdzamy stan płatności…</p>
    <div v-else-if="!booking" role="alert" class="stack">
      <p class="alert">{{ loadError }}</p>
      <button type="button" @click="start(reference)">Spróbuj ponownie</button>
    </div>

    <template v-else>
      <div aria-live="polite" class="stack" data-test="payment-result">
        <template v-if="booking.status === 'paid'">
          <h1>Płatność przyjęta</h1>
          <p>Bilety są gotowe. Potwierdzenie z biletami w PDF wyślemy też na adres e-mail Twojego konta.</p>
          <p><RouterLink class="button-link" :to="{ name: 'booking', params: { reference: booking.reference } }" data-test="show-tickets">Zobacz bilety</RouterLink></p>
        </template>
        <template v-else-if="booking.status === 'pending'">
          <h1>{{ redirectFailed ? 'Płatność nie została potwierdzona' : 'Czekamy na potwierdzenie płatności' }}</h1>
          <p v-if="redirectFailed">Operator płatności nie potwierdził zapłaty. Miejsca są nadal zarezerwowane — możesz spróbować ponownie.</p>
          <p v-else>To zwykle trwa kilka sekund. Nie zamykaj tej strony.</p>
          <p v-if="watchStatus === 'delayed'" data-test="payment-delayed">
            Potwierdzenie się opóźnia. Jeśli zapłaciłeś, rezerwacja zmieni status sama — sprawdź ją później na swoim koncie.
          </p>
          <RouterLink v-if="screening && (redirectFailed || watchStatus === 'delayed')" :to="{ name: 'checkout', params: { id: screening.id } }">
            Wróć do płatności
          </RouterLink>
        </template>
        <template v-else-if="booking.status === 'refunded'">
          <h1>Pieniądze zostały zwrócone</h1>
          <p>Wybrane miejsca nie były już dostępne, więc cała kwota wraca na Twoje konto.</p>
        </template>
        <template v-else>
          <h1>Płatność nie doszła do skutku</h1>
          <p>{{ booking.status === 'expired' ? 'Czas na płatność minął, a miejsca wróciły do puli.' : 'Rezerwacja została anulowana.' }} Nie pobraliśmy żadnych pieniędzy.</p>
        </template>
      </div>

      <dl v-if="screening" class="details">
        <dt>Film</dt>
        <dd>{{ screening.movie.title }}</dd>
        <dt>Seans</dt>
        <dd>{{ formatDayLabel(dateOf(screening.starts_at)) }}, godz. {{ timeOf(screening.starts_at) }}</dd>
        <dt>Kino</dt>
        <dd>{{ screening.hall.cinema.name }}, {{ screening.hall.name }}</dd>
        <dt>Kwota</dt>
        <dd>{{ booking.total.formatted }}</dd>
        <dt>Status</dt>
        <dd data-test="booking-status">{{ booking.status_label }}</dd>
        <dt>Numer rezerwacji</dt>
        <dd><code>{{ booking.reference }}</code></dd>
      </dl>

      <p>
        <RouterLink :to="{ name: 'account' }">Moje konto</RouterLink>
        <template v-if="screening && booking.status !== 'paid' && booking.status !== 'pending'">
          · <RouterLink :to="{ name: 'repertoire', params: { slug: screening.hall.cinema.slug } }">Repertuar kina</RouterLink>
        </template>
      </p>
    </template>
  </section>
</template>
