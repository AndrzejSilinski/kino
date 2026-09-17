<script setup lang="ts">
/*
 * Formularz płatności (Etap 8, blok H3): Payment Element Stripe'a i przycisk "Zapłać".
 *
 * Kontener formularza jest w DOM od początku (v-show, nie v-if) — Stripe montuje w nim swoje ramki
 * jeszcze w stanie "ładowanie". Nieudana próba (odrzucona karta, przerwane 3-D Secure) NIE zwalnia
 * miejsc: serwer trzyma je do końca okna płatności, więc klient poprawia dane i próbuje ponownie.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { createStripePaymentUi, type PaymentUi } from '@/payments/stripe';

const props = defineProps<{ publishableKey: string; clientSecret: string; returnUrl: string; amount: string; disabled?: boolean }>();
const emit = defineEmits<{ confirmed: [status: string]; busy: [value: boolean] }>();

const container = ref<HTMLElement | null>(null);
const state = ref<'loading' | 'ready' | 'submitting' | 'load-error'>('loading');
const error = ref<string | null>(null);
let ui: PaymentUi | null = null;
let disposed = false;

function prefersDark(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

async function setup(): Promise<void> {
  state.value = 'loading';
  error.value = null;
  ui?.destroy();
  ui = null;
  try {
    const created = await createStripePaymentUi({ publishableKey: props.publishableKey, clientSecret: props.clientSecret, dark: prefersDark() });
    if (disposed || !container.value) {
      created.destroy();
      return;
    }
    ui = created;
    await created.mount(container.value);
    if (!disposed) {
      state.value = 'ready';
    }
  } catch {
    // Skrypt z js.stripe.com zablokowany (bloker reklam, brak sieci) albo odrzucony client_secret.
    if (!disposed) {
      state.value = 'load-error';
    }
  }
}

async function submit(): Promise<void> {
  if (!ui || state.value !== 'ready' || props.disabled) {
    return;
  }
  state.value = 'submitting';
  error.value = null;
  emit('busy', true);
  try {
    const outcome = await ui.confirm(props.returnUrl);
    if (outcome.kind === 'confirmed') {
      emit('confirmed', outcome.status);
      return;
    }
    error.value = outcome.message;
  } catch {
    error.value = 'Nie udało się połączyć z operatorem płatności. Spróbuj ponownie.';
  } finally {
    if (!disposed) {
      if (state.value === 'submitting') {
        state.value = 'ready';
      }
      emit('busy', false);
    }
  }
}

onMounted(() => void setup());
onBeforeUnmount(() => {
  disposed = true;
  ui?.destroy();
  ui = null;
});
</script>

<template>
  <form class="stack payment-form" novalidate data-test="payment-form" @submit.prevent="submit">
    <p v-if="state === 'loading'" role="status">Wczytywanie formularza płatności…</p>
    <div v-show="state !== 'load-error'" ref="container" class="payment-element" data-test="payment-element" />
    <div v-if="state === 'load-error'" role="alert" class="stack">
      <p class="alert">Nie udało się wczytać formularza płatności. Sprawdź połączenie albo wyłącz blokowanie skryptów dla tej strony.</p>
      <button type="button" data-test="payment-reload" @click="setup">Spróbuj ponownie</button>
    </div>
    <p v-if="error" role="alert" class="alert" data-test="payment-error">{{ error }}</p>
    <button v-if="state !== 'load-error'" type="submit" :disabled="state !== 'ready' || disabled" data-test="pay">
      {{ state === 'submitting' ? 'Przetwarzanie płatności…' : `Zapłać ${amount}` }}
    </button>
  </form>
</template>
