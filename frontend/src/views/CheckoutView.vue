<script setup lang="ts">
/*
 * Podsumowanie i płatność (Etap 8, blok H2; formularz Stripe z bloku H3).
 *
 * Etapy ekranu wynikają ze stanu SERWERA, nie z historii kliknięć:
 *   - koszyk bez pending_booking   -> podsumowanie z przyciskiem "Przejdź do płatności" (POST checkout),
 *   - koszyk z pending_booking     -> płatność już trwa: checkout jeszcze raz (idempotentny) po client_secret,
 *   - pusty koszyk                 -> nic do opłacenia, powrót do planu sali.
 * Dzięki temu F5, powrót z logowania i druga karta lądują w tym samym miejscu.
 *
 * Timer podsumowania = najwcześniejsza blokada koszyka; timer płatności = okno płatności z checkoutu
 * (serwer wydłuża wtedy blokady do terminu rezerwacji).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import PaymentForm from '@/components/checkout/PaymentForm.vue';
import CartPanel from '@/components/seats/CartPanel.vue';
import CountdownTimer from '@/components/seats/CountdownTimer.vue';
import { dateOf, formatDayLabel, timeOf } from '@/lib/datetime';
import { useCartStore } from '@/stores/cart';
import { useCheckoutStore } from '@/stores/checkout';
import { useSeatMapStore } from '@/stores/seatMap';

const route = useRoute();
const router = useRouter();
const seatMap = useSeatMapStore();
const cart = useCartStore();
const checkout = useCheckoutStore();

const id = computed(() => Number(route.params.id));
const screening = computed(() => seatMap.screening);
const loading = ref(true);
/** Trwa potwierdzanie w Stripe.js (np. okno 3-D Secure): rezygnacja w tej chwili rozjechałaby stany. */
const paying = ref(false);

type Stage = 'loading' | 'error' | 'starting' | 'payment' | 'expired' | 'empty' | 'summary';

const stage = computed<Stage>(() => {
  if (loading.value) return 'loading';
  if (!screening.value) return 'error';
  if (checkout.phase === 'expired') return 'expired';
  if (checkout.result && (checkout.phase === 'ready' || checkout.phase === 'abandoning')) return 'payment';
  if (checkout.phase === 'starting') return 'starting';
  if (cart.seatsCount === 0) return 'empty';
  return 'summary';
});

const seatsLink = computed(() => ({ name: 'screening-seats', params: { id: id.value } }));

async function load(screeningId: number): Promise<void> {
  loading.value = true;
  if (checkout.screeningId !== screeningId) {
    checkout.reset(screeningId);
  }
  await cart.start(screeningId, cart.maxSeats);
  loading.value = false;
  if (cart.pendingBooking !== null) {
    await checkout.start(screeningId);
  }
}

async function pay(): Promise<void> {
  if (await checkout.start(id.value)) {
    // Koszyk z pending_booking: plan sali po powrocie wie, że płatność trwa.
    await cart.refreshCart();
  }
}

async function releaseExtraAndPay(): Promise<void> {
  if (await cart.releaseExtraSeats()) {
    await pay();
  }
}

async function abandon(): Promise<void> {
  if (await checkout.abandon()) {
    await cart.afterPaymentAbandoned();
    await router.push(seatsLink.value);
  }
}

/** Adres powrotu dla metod z przekierowaniem; Stripe dopisze do niego parametry, które usuwa router. */
const returnUrl = computed(() => {
  const reference = checkout.result?.booking.reference ?? '';
  return `${window.location.origin}${router.resolve({ name: 'payment-result', params: { reference } }).href}`;
});

/** Stripe.js skończył swoją część; o opłaceniu rozstrzyga webhook — czeka na niego ekran wyniku. */
async function onConfirmed(): Promise<void> {
  const reference = checkout.result?.booking.reference;
  if (!reference) {
    return;
  }
  await router.push({ name: 'payment-result', params: { reference } });
  // client_secret nie jest już potrzebny — nie trzymamy go w pamięci dłużej, niż trzeba.
  checkout.reset();
}

function onVisibility(): void {
  if (document.visibilityState !== 'visible') {
    return;
  }
  // Uśpiony laptop: licznik mógł stanąć. Świeży termin z serwera zamiast lokalnego zegara.
  if (stage.value === 'payment' && !paying.value) {
    void checkout.start(id.value);
  } else if (stage.value === 'summary') {
    void cart.resync();
  }
}

watch(id, (value) => {
  if (Number.isInteger(value) && value > 0) {
    void load(value);
  }
}, { immediate: true });

onMounted(() => document.addEventListener('visibilitychange', onVisibility));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibility));
</script>

<template>
  <section class="stack checkout">
    <p v-if="stage === 'loading'" role="status">Wczytywanie zamówienia…</p>
    <div v-else-if="stage === 'error'" role="alert" class="stack">
      <p class="alert">{{ seatMap.errorMessage ?? 'Nie udało się wczytać seansu.' }}</p>
      <button v-if="!seatMap.notFound" type="button" @click="load(id)">Spróbuj ponownie</button>
    </div>

    <template v-else-if="screening">
      <header class="stack">
        <h1>{{ stage === 'payment' ? 'Płatność' : 'Podsumowanie zamówienia' }}</h1>
        <p>
          <strong>{{ screening.movie.title }}</strong> ·
          {{ formatDayLabel(dateOf(screening.starts_at)) }}, godz. {{ timeOf(screening.starts_at) }}
          · {{ screening.hall.cinema.name }}, {{ screening.hall.name }}
          · {{ screening.projection_type_label }}, {{ screening.language_version_label }}
        </p>
      </header>

      <div v-if="checkout.problem" role="alert" class="stack notice notice-error checkout-problem" data-test="checkout-problem">
        <p>{{ checkout.problem.message }}</p>
        <div class="cart-actions">
          <button v-if="checkout.problem.code === 'BOOKING_ALREADY_PENDING'" type="button" data-test="release-extra" @click="releaseExtraAndPay">
            Zwolnij dobrane miejsca i wróć do płatności
          </button>
          <button v-else-if="checkout.problem.retryable" type="button" data-test="retry" @click="pay">Spróbuj ponownie</button>
          <RouterLink v-if="checkout.problem.code === 'BOOKING_NOT_PAYABLE' && checkout.problem.bookingStatus === 'paid'" :to="{ name: 'account' }">
            Przejdź do swoich rezerwacji
          </RouterLink>
          <RouterLink v-else :to="seatsLink">Wróć do planu sali</RouterLink>
        </div>
      </div>

      <p v-if="stage === 'starting'" role="status">Przygotowujemy płatność…</p>

      <div v-else-if="stage === 'expired'" role="alert" class="stack" data-test="payment-expired">
        <p class="alert">Czas na płatność minął. Miejsca wracają do puli — wybierz je ponownie.</p>
        <RouterLink :to="seatsLink">Wróć do planu sali</RouterLink>
      </div>

      <div v-else-if="stage === 'empty'" class="stack" data-test="checkout-empty">
        <p>Koszyk jest pusty albo blokady miejsc wygasły.</p>
        <RouterLink :to="seatsLink">Wybierz miejsca</RouterLink>
      </div>

      <div v-else-if="stage === 'summary'" class="seat-selection-layout">
        <CartPanel :cart="cart.cart" :max-seats="cart.maxSeats" :busy="false" readonly />
        <div class="stack">
          <CountdownTimer :deadline="cart.deadline" @expire="cart.onExpired" />
          <p>Płatność potwierdza zakup. Po jej rozpoczęciu miejsca są zarezerwowane na czas płatności.</p>
          <button type="button" data-test="start-payment" @click="pay">Przejdź do płatności</button>
          <RouterLink :to="seatsLink">Zmień wybór miejsc</RouterLink>
        </div>
      </div>

      <div v-else-if="stage === 'payment' && checkout.result" class="seat-selection-layout">
        <CartPanel :cart="cart.cart" :max-seats="cart.maxSeats" :busy="false" readonly />
        <section class="stack" aria-labelledby="payment-heading">
          <h2 id="payment-heading">Do zapłaty: <span data-test="payment-total">{{ checkout.result.booking.total.formatted }}</span></h2>
          <CountdownTimer :deadline="checkout.deadline" @expire="checkout.onPaymentExpired" />
          <PaymentForm
            :key="checkout.result.payment.client_secret"
            :publishable-key="checkout.result.payment.publishable_key"
            :client-secret="checkout.result.payment.client_secret"
            :return-url="returnUrl"
            :amount="checkout.result.booking.total.formatted"
            :disabled="checkout.phase === 'abandoning'"
            @busy="paying = $event"
            @confirmed="onConfirmed"
          />
          <button type="button" class="link-button" :disabled="checkout.phase === 'abandoning' || paying" data-test="abandon-payment" @click="abandon">
            Zrezygnuj z płatności
          </button>
        </section>
      </div>
    </template>
  </section>
</template>
