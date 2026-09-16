<script setup lang="ts">
/* Szkielet układu: nagłówek, treść, stopka. Treść podmienia RouterView. */
import { RouterLink, RouterView } from 'vue-router';
</script>

<template>
  <a class="skip-link" href="#main">Przejdź do treści</a>
  <header class="site-header">
    <RouterLink to="/" class="brand">Kino</RouterLink>
  </header>
  <main id="main" class="site-main" tabindex="-1">
    <RouterView />
  </main>
  <footer class="site-footer">
    <small>Bilety online · płatności w trybie testowym</small>
  </footer>
</template>
