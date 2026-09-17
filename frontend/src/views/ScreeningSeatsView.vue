<script setup lang="ts">
/*
 * Wybór miejsc na seansie (wymóg 3.2). Logika blokowania jest w store'ach (cart, seatMap)
 * i w czystych modułach (seatState, seatLayout) — widok tylko łączy je z komponentami.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { loadClientConfig } from '@/api/clientConfig';
import PendingPaymentNotice from '@/components/checkout/PendingPaymentNotice.vue';
import RealtimeBanner from '@/components/realtime/RealtimeBanner.vue';
import CartPanel from '@/components/seats/CartPanel.vue';
import CountdownTimer from '@/components/seats/CountdownTimer.vue';
import SeatLegend from '@/components/seats/SeatLegend.vue';
import SeatMap from '@/components/seats/SeatMap.vue';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';
import { buildSeatLayout } from '@/lib/seatLayout';
import { getRealtimeConnection } from '@/realtime';
import { createSeatSync, type SeatSync, type SyncStatus } from '@/realtime/seatSync';
import { DEFAULT_MAX_SEATS, useCartStore } from '@/stores/cart';
import { useCheckoutStore } from '@/stores/checkout';
import { useSeatMapStore } from '@/stores/seatMap';

const route = useRoute();
const router = useRouter();
const seatMap = useSeatMapStore();
const cart = useCartStore();
const checkout = useCheckoutStore();

const id = computed(() => Number(route.params.id));
const screening = computed(() => seatMap.screening);
const layout = computed(() => (screening.value ? buildSeatLayout(seatMap.seats, screening.value.hall.grid) : null));

const syncStatus = ref<SyncStatus>('connecting');
const refreshing = ref(false);
let sync: SeatSync | null = null;

/** Na żywo (blok G): migawka -> subskrypcja -> migawka po subscription_succeeded (decyzja 125). */
async function startRealtime(screeningId: number): Promise<void> {
  sync?.stop();
  sync = null;
  syncStatus.value = 'connecting';
  try {
    const connection = await getRealtimeConnection();
    if (id.value !== screeningId) {
      return;
    }
    sync = createSeatSync({
      screeningId,
      connection,
      target: {
        version: () => seatMap.version,
        loadSnapshot: async () => {
          await seatMap.load(screeningId);
        },
        applyChanges: (changes, version) => {
          seatMap.applyChanges(changes, version);
          cart.onSeatChanges(changes);
        },
      },
      onStatus: (status) => {
        syncStatus.value = status;
      },
    });
    await sync.start();
  } catch {
    // Brak konfiguracji albo biblioteki: plan działa przez REST, baner proponuje ręczne odświeżenie.
    syncStatus.value = 'unavailable';
  }
}

async function refreshPlan(): Promise<void> {
  refreshing.value = true;
  try {
    await cart.resync();
  } finally {
    refreshing.value = false;
  }
}

async function start(screeningId: number): Promise<void> {
  const maxSeats = await loadClientConfig().then((config) => config.booking.max_seats_per_session).catch(() => DEFAULT_MAX_SEATS);
  await cart.start(screeningId, maxSeats);
  if (seatMap.screening?.is_bookable) {
    await startRealtime(screeningId);
  }
}

function goToCheckout(): void {
  void router.push({ name: 'checkout', params: { id: id.value } });
}

/** Rezygnacja z rozpoczętej płatności prosto z planu sali (blok H2). */
async function abandonPayment(): Promise<void> {
  const reference = cart.pendingBooking?.reference ?? null;
  if (await checkout.abandon(reference)) {
    await cart.afterPaymentAbandoned();
  } else if (checkout.problem) {
    cart.notice = { tone: 'error', text: checkout.problem.message };
  }
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
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibility);
  // Wyjście z widoku = wypisanie z kanału seansu.
  sync?.stop();
  sync = null;
});
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
      <RealtimeBanner v-else :status="syncStatus" :refreshing="refreshing" @refresh="refreshPlan" />
      <div v-if="cart.notice" :role="cart.notice.tone === 'error' ? 'alert' : 'status'" class="notice" :class="`notice-${cart.notice.tone}`" data-test="cart-notice">
        <span>{{ cart.notice.text }}</span>
        <button type="button" class="link-button" aria-label="Zamknij komunikat" @click="cart.dismissNotice">✕</button>
      </div>

      <PendingPaymentNotice v-if="cart.pendingBooking" :screening-id="id" :busy="checkout.phase === 'abandoning'" @abandon="abandonPayment" />

      <div class="seat-selection-layout">
        <div class="stack">
          <SeatMap :layout="layout" :own-seat-ids="cart.ownSeatIds" :pending-seat-ids="cart.pending" :disabled="cart.closed || cart.pendingBooking !== null" @toggle="cart.toggle" />
          <SeatLegend />
        </div>
        <div class="stack seat-selection-side">
          <CountdownTimer :deadline="cart.deadline" @expire="cart.onExpired" />
          <CartPanel :cart="cart.cart" :max-seats="cart.maxSeats" :busy="cart.pending.size > 0" :readonly="cart.pendingBooking !== null" @clear="cart.clear">
            <template v-if="!cart.pendingBooking" #checkout>
              <!-- Wyłączony, dopóki serwer nie potwierdzi kliknięć w kolejce: podsumowanie pokazałoby stary koszyk. -->
              <button type="button" :disabled="cart.pending.size > 0" data-test="go-to-checkout" @click="goToCheckout">Przejdź do podsumowania</button>
            </template>
          </CartPanel>
        </div>
      </div>
    </template>
  </section>
</template>
