<script setup lang="ts">
/*
 * Powiadomienia w tej przeglądarce (Etap 8, blok L). Każdy stan ma własny, konkretny komunikat:
 * klient z zablokowanym uprawnieniem musi wiedzieć, że zmienia je w ustawieniach przeglądarki,
 * bo strona nie może ponownie zapytać (przeglądarka pamięta odmowę).
 */
import { onMounted } from 'vue';
import { useWebPushStore } from '@/stores/webPush';

const emit = defineEmits<{ changed: [] }>();
const push = useWebPushStore();

async function enable(): Promise<void> {
  if (await push.enable()) {
    emit('changed');
  }
}

onMounted(() => void push.check());
</script>

<template>
  <section class="stack browser-push" aria-labelledby="browser-push-heading" data-test="browser-push">
    <h3 id="browser-push-heading">Ta przeglądarka</h3>
    <p v-if="push.status === 'checking'" role="status">Sprawdzanie obsługi powiadomień…</p>
    <p v-else-if="push.status === 'unavailable'" class="field-hint" data-test="push-unavailable">
      Powiadomienia push nie są jeszcze włączone w tej instalacji kina.
    </p>
    <p v-else-if="push.status === 'unsupported'" class="field-hint" data-test="push-unsupported">
      Ta przeglądarka (albo tryb prywatny) nie obsługuje powiadomień push. Przypomnienia dostaniesz e-mailem.
    </p>
    <p v-else-if="push.status === 'denied'" class="notice" data-test="push-denied">
      Powiadomienia dla tej strony są zablokowane w ustawieniach przeglądarki. Zmień to przy ikonie kłódki obok adresu,
      a potem odśwież stronę.
    </p>
    <template v-else-if="push.status === 'on'">
      <p data-test="push-on">Powiadomienia są włączone w tej przeglądarce.</p>
      <button type="button" class="link-button" :disabled="push.busy" data-test="push-disable" @click="push.disable">Wyłącz w tej przeglądarce</button>
    </template>
    <template v-else>
      <p class="field-hint">Przeglądarka zapyta o zgodę. Włączenie zapisze też zgodę na push na Twoim koncie.</p>
      <button type="button" :disabled="push.busy" data-test="push-enable" @click="enable">
        {{ push.busy ? 'Włączanie…' : 'Włącz powiadomienia w tej przeglądarce' }}
      </button>
    </template>
    <p v-if="push.message" role="alert" class="alert" data-test="push-message">{{ push.message }}</p>
  </section>
</template>
