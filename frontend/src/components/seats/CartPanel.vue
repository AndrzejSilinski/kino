<script setup lang="ts">
/*
 * Wybrane miejsca na bieżąco: rząd, numer, kategoria, cena jednostkowa i suma (wymóg 3.2).
 * Wszystkie kwoty sformatowane przez serwer (decyzja 23) — front niczego nie sumuje.
 */
import type { Cart } from '@/api/types';

// readonly: podsumowanie przed płatnością i plan sali z rozpoczętą płatnością — bez "Wyczyść wybór".
defineProps<{ cart: Cart | null; maxSeats: number; busy: boolean; readonly?: boolean }>();
const emit = defineEmits<{ clear: [] }>();
</script>

<template>
  <aside class="cart-panel" aria-labelledby="cart-heading">
    <h2 id="cart-heading">Twój wybór</h2>
    <p v-if="!cart || cart.seats_count === 0" class="cart-empty">Nie wybrano jeszcze miejsc.</p>
    <template v-else>
      <ul class="cart-seats">
        <li v-for="seat in cart.seats" :key="seat.seat_id" :data-test="`cart-seat-${seat.seat_id}`">
          <span>Rząd {{ seat.row }}, miejsce {{ seat.number }}<template v-if="seat.type === 'double'"> (podwójne)</template></span>
          <span class="cart-category">{{ seat.category.name }}</span>
          <span class="cart-price">{{ seat.price.formatted }}</span>
        </li>
      </ul>
      <p class="cart-total">
        <span>Razem ({{ cart.seats_count }} z {{ maxSeats }} możliwych)</span>
        <strong data-test="cart-total">{{ cart.total.formatted }}</strong>
      </p>
      <div class="cart-actions">
        <button v-if="!readonly" type="button" class="link-button" :disabled="busy" @click="emit('clear')">Wyczyść wybór</button>
        <slot name="checkout" />
      </div>
    </template>
  </aside>
</template>
