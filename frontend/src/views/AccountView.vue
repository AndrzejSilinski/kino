<script setup lang="ts">
/*
 * Konto (Etap 8, blok J): układ z nawigacją i podstronami — historia zakupów, profil, powiadomienia.
 * Podstrony to trasy potomne (/account, /account/profile, /account/notifications): każda ma własny
 * adres, więc działa odświeżenie, przycisk wstecz i link z e-maila albo z powiadomienia.
 */
import { RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
</script>

<template>
  <section class="stack account">
    <header class="account-header">
      <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="avatar" width="56" height="56" />
      <span v-else class="avatar avatar-placeholder" aria-hidden="true">{{ auth.user?.name.charAt(0).toUpperCase() ?? '?' }}</span>
      <div>
        <h1>Moje konto</h1>
        <p v-if="auth.user" class="field-hint">{{ auth.user.name }} · {{ auth.user.email }}</p>
      </div>
    </header>
    <nav aria-label="Sekcje konta" class="account-nav">
      <RouterLink :to="{ name: 'account' }" exact-active-class="is-active">Rezerwacje</RouterLink>
      <RouterLink :to="{ name: 'account-profile' }" active-class="is-active">Profil i hasło</RouterLink>
      <RouterLink :to="{ name: 'account-notifications' }" active-class="is-active">Powiadomienia</RouterLink>
    </nav>
    <RouterView />
  </section>
</template>
