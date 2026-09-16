<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'home' });
}
</script>

<template>
  <header class="site-header">
    <RouterLink to="/" class="brand">Kino</RouterLink>
    <nav aria-label="Konto">
      <template v-if="auth.isAuthenticated">
        <RouterLink :to="{ name: 'account' }">{{ auth.user?.name ?? 'Moje konto' }}</RouterLink>
        <button type="button" class="link-button" @click="logout">Wyloguj</button>
      </template>
      <template v-else>
        <RouterLink :to="{ name: 'login' }">Zaloguj się</RouterLink>
        <RouterLink :to="{ name: 'register' }">Załóż konto</RouterLink>
      </template>
    </nav>
  </header>
</template>
