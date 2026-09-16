<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import FormField from '@/components/FormField.vue';
import { useApiForm } from '@/composables/useApiForm';
import { safeRedirect } from '@/router/guards';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const form = useApiForm();

const email = ref('');
const password = ref('');
const expired = route.query.reason === 'expired';

async function onSubmit(): Promise<void> {
  const ok = await form.submit(() => auth.login(email.value, password.value));
  if (ok) {
    await router.replace(safeRedirect(route.query.redirect));
  }
}
</script>

<template>
  <section class="stack narrow">
    <h1>Logowanie</h1>
    <p v-if="expired" role="status" class="notice">Sesja zakończyła się (wylogowanie w innej karcie albo wygaśnięcie). Zaloguj się ponownie.</p>
    <p v-if="form.formError.value" role="alert" class="alert" data-test="form-error">{{ form.formError.value }}</p>

    <form class="stack" novalidate @submit.prevent="onSubmit">
      <FormField id="email" v-model="email" label="Adres e-mail" type="email" autocomplete="email" required :error="form.errorFor('email')" />
      <FormField id="password" v-model="password" label="Hasło" type="password" autocomplete="current-password" required :error="form.errorFor('password')" />
      <button type="submit" :disabled="form.pending.value" :aria-busy="form.pending.value">
        {{ form.pending.value ? 'Logowanie…' : 'Zaloguj się' }}
      </button>
    </form>

    <p>Nie masz konta? <RouterLink :to="{ name: 'register', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">Załóż konto</RouterLink></p>
  </section>
</template>
