<script setup lang="ts">
/* Konto — w bloku J dojdą historia zakupów, profil, hasło, avatar i powiadomienia. */
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
</script>

<template>
  <section class="stack">
    <h1>Moje konto</h1>
    <dl v-if="auth.user" class="details">
      <dt>Imię i nazwisko</dt>
      <dd data-test="user-name">{{ auth.user.name }}</dd>
      <dt>Adres e-mail</dt>
      <dd>{{ auth.user.email }}</dd>
    </dl>
    <p v-else role="status">Nie udało się pobrać danych konta. Odśwież stronę.</p>
  </section>
</template>
