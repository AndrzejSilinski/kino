<script setup lang="ts">
/*
 * Szczegóły rezerwacji z biletami (Etap 8, blok H4): kody QR na ekranie i PDF do pobrania.
 *
 * Bilety pokazujemy tylko dla rezerwacji OPŁACONEJ — to samo sprawdza serwer (409
 * BOOKING_TICKETS_UNAVAILABLE), a front nie wysyła żądań, o których wie, że skończą się odmową.
 * Adres /bookings/{reference} (ULID) jest też celem deep linku aplikacji mobilnej w Etapie 9.
 */
import { computed, ref, shallowRef, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { bookingsApi } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { Booking, Ticket } from '@/api/types';
import { useTicketQrs } from '@/composables/useTicketQrs';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';
import { saveBlob } from '@/lib/download';
import { messageFor } from '@/messages';

const route = useRoute();
const reference = computed(() => String(route.params.reference));

const booking = shallowRef<Booking | null>(null);
const loadError = ref<string | null>(null);
const notFound = ref(false);
const pdfBusy = ref(false);
const pdfError = ref<string | null>(null);
const qrs = useTicketQrs((url, signal) => bookingsApi.ticketQr(url, signal));

const screening = computed(() => booking.value?.screening ?? null);
const tickets = computed<Ticket[]>(() => booking.value?.tickets ?? []);
const paid = computed(() => booking.value?.status === 'paid');

async function load(value: string): Promise<void> {
  booking.value = null;
  loadError.value = null;
  notFound.value = false;
  try {
    const result = await bookingsApi.show(value);
    if (reference.value !== value) {
      return;
    }
    booking.value = result;
    if (result.status === 'paid') {
      await qrs.load(result.tickets ?? []);
    }
  } catch (error) {
    if (reference.value !== value) {
      return;
    }
    if (isApiError(error) && (error.status === 403 || error.status === 404)) {
      notFound.value = true;
    } else {
      loadError.value = messageFor(error);
    }
  }
}

async function downloadPdf(): Promise<void> {
  if (!booking.value || pdfBusy.value) {
    return;
  }
  pdfBusy.value = true;
  pdfError.value = null;
  try {
    const { blob, filename } = await bookingsApi.ticketsPdf(booking.value.reference);
    saveBlob(blob, filename ?? `bilety-${booking.value.reference}.pdf`);
  } catch (error) {
    pdfError.value = messageFor(error);
  } finally {
    pdfBusy.value = false;
  }
}

function seatText(ticket: Ticket): string {
  return ticket.seat ? `Rząd ${ticket.seat.row}, miejsce ${ticket.seat.number}${ticket.seat.type === 'double' ? ' (podwójne)' : ''}` : `Bilet ${ticket.id}`;
}

watch(reference, (value) => void load(value), { immediate: true });
</script>

<template>
  <section class="stack booking-view">
    <div v-if="notFound" role="alert" class="stack">
      <h1>Nie znaleziono rezerwacji</h1>
      <p>Ta rezerwacja nie istnieje albo należy do innego konta.</p>
      <RouterLink to="/">Wróć na stronę główną</RouterLink>
    </div>
    <p v-else-if="!booking && !loadError" role="status">Wczytywanie rezerwacji…</p>
    <div v-else-if="!booking" role="alert" class="stack">
      <p class="alert">{{ loadError }}</p>
      <button type="button" @click="load(reference)">Spróbuj ponownie</button>
    </div>

    <template v-else>
      <header class="stack">
        <h1>{{ screening?.movie.title ?? 'Rezerwacja' }}</h1>
        <p v-if="screening">
          {{ formatDayLabel(dateOf(screening.starts_at)) }}, godz. {{ timeOf(screening.starts_at) }}
          · {{ screening.hall.cinema.name }}, {{ screening.hall.name }}
          · {{ screening.projection_type_label }}, {{ screening.language_version_label }}
        </p>
        <p>
          Status: <strong data-test="booking-status">{{ booking.status_label }}</strong> · {{ booking.total.formatted }}
          · numer <code>{{ booking.reference }}</code>
        </p>
      </header>

      <div v-if="!paid" class="notice" data-test="tickets-unavailable">
        <p v-if="booking.status === 'pending'">
          Rezerwacja czeka na płatność — bilety pojawią się po jej potwierdzeniu.
          <RouterLink :to="{ name: 'payment-result', params: { reference: booking.reference } }">Sprawdź stan płatności</RouterLink>
        </p>
        <p v-else>Bilety tej rezerwacji nie są ważne ({{ booking.status_label.toLowerCase() }}).</p>
      </div>

      <template v-else>
        <div class="cart-actions">
          <button type="button" :disabled="pdfBusy" data-test="download-pdf" @click="downloadPdf">
            {{ pdfBusy ? 'Przygotowywanie PDF…' : 'Pobierz bilety (PDF)' }}
          </button>
        </div>
        <p v-if="pdfError" role="alert" class="alert" data-test="pdf-error">{{ pdfError }}</p>

        <ul class="tickets" aria-label="Bilety">
          <li v-for="ticket in tickets" :key="ticket.id" class="ticket" :class="`ticket-${ticket.status}`" :data-test="`ticket-${ticket.id}`">
            <div class="ticket-info">
              <strong>{{ seatText(ticket) }}</strong>
              <span>{{ ticket.price.formatted }} · {{ ticket.status_label }}</span>
            </div>
            <template v-if="ticket.status !== 'cancelled'">
              <img
                v-if="qrs.states.value.get(ticket.id)?.kind === 'ready'"
                class="ticket-qr"
                :src="(qrs.states.value.get(ticket.id) as { url: string }).url"
                :alt="`Kod QR biletu: ${seatText(ticket).toLowerCase()}`"
                width="200"
                height="200"
              />
              <p v-else-if="qrs.states.value.get(ticket.id)?.kind === 'error'" class="alert" data-test="qr-error">Nie udało się wczytać kodu QR.</p>
              <p v-else role="status">Wczytywanie kodu QR…</p>
            </template>
          </li>
        </ul>
        <button v-if="[...qrs.states.value.values()].some((state) => state.kind === 'error')" type="button" class="link-button" @click="qrs.load(tickets)">
          Wczytaj kody QR ponownie
        </button>
        <p class="field-hint">Pokaż kod QR przy wejściu na salę albo wydrukuj PDF. Nie udostępniaj kodów — każdy działa jak bilet.</p>
      </template>
    </template>
  </section>
</template>
