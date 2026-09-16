<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
  <section class="stack">
    <h1>Nie znaleziono strony</h1>
    <p>Adres jest nieprawidłowy albo strona została usunięta.</p>
    <p><RouterLink to="/">Wróć do wyboru kina</RouterLink></p>
  </section>
</template>
