<script setup lang="ts">
/*
 * Plan sali z rozpoczętą płatnością (Etap 8, blok H2). Miejsca należą do rezerwacji, więc plan jest
 * zamrożony; klient wybiera: wrócić do płatności albo z niej zrezygnować (miejsca wracają od razu,
 * zamiast po oknie płatności).
 */
import { RouterLink } from 'vue-router';

defineProps<{ screeningId: number; busy: boolean }>();
const emit = defineEmits<{ abandon: [] }>();
</script>

<template>
  <div class="notice pending-payment" role="status" data-test="pending-payment">
    <p>Masz rozpoczętą płatność za wybrane miejsca. Plan sali jest zablokowany do jej zakończenia.</p>
    <div class="cart-actions">
      <RouterLink class="button-link" :to="{ name: 'checkout', params: { id: screeningId } }">Wróć do płatności</RouterLink>
      <button type="button" class="link-button" :disabled="busy" data-test="abandon-payment" @click="emit('abandon')">Zrezygnuj z płatności</button>
    </div>
  </div>
</template>
