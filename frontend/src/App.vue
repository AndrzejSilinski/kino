<script setup lang="ts">
/* Szkielet układu: nagłówek z kontem, treść, stopka. Treść podmienia RouterView. */
import { RouterView } from 'vue-router';
import AppHeader from '@/components/AppHeader.vue';
</script>

<template>
  <a class="skip-link" href="#main">Przejdź do treści</a>
  <AppHeader />
  <main id="main" class="site-main" tabindex="-1">
    <RouterView />
  </main>
  <footer class="site-footer">
    <small>Bilety online · płatności w trybie testowym</small>
  </footer>
</template>
