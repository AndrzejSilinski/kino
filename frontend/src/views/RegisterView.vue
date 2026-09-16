<script setup lang="ts">
import { reactive } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import FormField from '@/components/FormField.vue';
import { useApiForm } from '@/composables/useApiForm';
import { safeRedirect } from '@/router/guards';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const form = useApiForm();

const data = reactive({ name: '', email: '', password: '', password_confirmation: '' });

async function onSubmit(): Promise<void> {
  const ok = await form.submit(() => auth.register({ ...data }));
  if (ok) {
    await router.replace(safeRedirect(route.query.redirect));
  }
}
</script>

<template>
  <section class="stack narrow">
    <h1>Załóż konto</h1>
    <p v-if="form.formError.value" role="alert" class="alert" data-test="form-error">{{ form.formError.value }}</p>

    <form class="stack" novalidate @submit.prevent="onSubmit">
      <FormField id="name" v-model="data.name" label="Imię i nazwisko" autocomplete="name" required :error="form.errorFor('name')" />
      <FormField id="email" v-model="data.email" label="Adres e-mail" type="email" autocomplete="email" required :error="form.errorFor('email')" />
      <FormField
        id="password" v-model="data.password" label="Hasło" type="password" autocomplete="new-password" required
        hint="Co najmniej 8 znaków, w tym litery i cyfry." :error="form.errorFor('password')"
      />
      <FormField
        id="password_confirmation" v-model="data.password_confirmation" label="Powtórz hasło" type="password"
        autocomplete="new-password" required :error="form.errorFor('password_confirmation')"
      />
      <button type="submit" :disabled="form.pending.value" :aria-busy="form.pending.value">
        {{ form.pending.value ? 'Zakładanie konta…' : 'Załóż konto' }}
      </button>
    </form>

    <p>Masz już konto? <RouterLink :to="{ name: 'login', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">Zaloguj się</RouterLink></p>
  </section>
</template>
