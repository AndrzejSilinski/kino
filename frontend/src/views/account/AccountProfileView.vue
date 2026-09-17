<script setup lang="ts">
/*
 * Profil i hasło (Etap 8, blok J, wymóg 3.4): imię i nazwisko, avatar, zmiana hasła.
 *
 * Każda sekcja to osobny formularz z własnym stanem (useApiForm): błąd hasła nie może
 * wyczyścić zmienionego imienia, a wysyłanie avatara nie blokuje pozostałych pól.
 * E-maila nie da się tu zmienić (decyzja z bloku I) — pokazujemy go tylko do odczytu.
 */
import { ref, watch } from 'vue';
import { accountApi } from '@/api/client';
import FormField from '@/components/FormField.vue';
import { useApiForm } from '@/composables/useApiForm';
import { avatarFileProblem } from '@/lib/avatarFile';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

// ─── Imię i nazwisko ──────────────────────────────────────────────────
const profileForm = useApiForm();
const name = ref(auth.user?.name ?? '');
const profileSaved = ref(false);
watch(() => auth.user?.name, (value) => {
  if (value !== undefined && !profileForm.pending.value) {
    name.value = value;
  }
});

async function saveProfile(): Promise<void> {
  profileSaved.value = false;
  profileSaved.value = await profileForm.submit(async () => auth.setUser(await accountApi.updateProfile(name.value)));
}

// ─── Avatar ───────────────────────────────────────────────────────────
const avatarForm = useApiForm();
const avatarProblem = ref<string | null>(null);

async function onAvatarSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  avatarProblem.value = null;
  if (!file) {
    return;
  }
  const problem = avatarFileProblem(file);
  if (problem) {
    avatarProblem.value = problem;
  } else {
    await avatarForm.submit(async () => auth.setUser(await accountApi.uploadAvatar(file)));
  }
  // Ten sam plik wybrany ponownie po błędzie też ma wywołać "change".
  input.value = '';
}

async function removeAvatar(): Promise<void> {
  avatarProblem.value = null;
  await avatarForm.submit(async () => auth.setUser(await accountApi.deleteAvatar()));
}

// ─── Hasło ────────────────────────────────────────────────────────────
const passwordForm = useApiForm();
const currentPassword = ref('');
const newPassword = ref('');
const confirmation = ref('');
const passwordMessage = ref<string | null>(null);

async function changePassword(): Promise<void> {
  passwordMessage.value = null;
  let revoked = 0;
  const ok = await passwordForm.submit(async () => {
    revoked = (await accountApi.changePassword({ current_password: currentPassword.value, password: newPassword.value, password_confirmation: confirmation.value })).revoked_tokens;
  });
  if (ok) {
    currentPassword.value = '';
    newPassword.value = '';
    confirmation.value = '';
    passwordMessage.value = revoked > 0
      ? `Hasło zostało zmienione. Wylogowaliśmy pozostałe urządzenia (${revoked}).`
      : 'Hasło zostało zmienione.';
  }
}
</script>

<template>
  <div class="stack account-profile">
    <section class="stack narrow" aria-labelledby="profile-heading">
      <h2 id="profile-heading">Dane</h2>
      <form class="stack" novalidate data-test="profile-form" @submit.prevent="saveProfile">
        <FormField id="profile-name" v-model="name" label="Imię i nazwisko" autocomplete="name" required :error="profileForm.errorFor('name')" />
        <p class="field-hint">Adres e-mail: {{ auth.user?.email }} (służy do logowania i wysyłki biletów, nie można go tu zmienić).</p>
        <p v-if="profileForm.formError.value" role="alert" class="alert">{{ profileForm.formError.value }}</p>
        <p v-if="profileSaved" role="status" data-test="profile-saved">Zapisano.</p>
        <button type="submit" :disabled="profileForm.pending.value">{{ profileForm.pending.value ? 'Zapisywanie…' : 'Zapisz' }}</button>
      </form>
    </section>

    <section class="stack narrow" aria-labelledby="avatar-heading">
      <h2 id="avatar-heading">Avatar</h2>
      <div class="account-header">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="Twój avatar" class="avatar avatar-large" width="96" height="96" data-test="avatar-image" />
        <span v-else class="avatar avatar-large avatar-placeholder" aria-hidden="true">{{ auth.user?.name.charAt(0).toUpperCase() ?? '?' }}</span>
        <div class="stack">
          <!-- Pole przed etykietą: styl fokusu (input:focus-visible + label) pokazuje, gdzie jest klawiatura. -->
          <input id="avatar-file" class="visually-hidden" type="file" accept="image/jpeg,image/png" aria-describedby="avatar-hint" data-test="avatar-input" :disabled="avatarForm.pending.value" @change="onAvatarSelected" />
          <label class="button-link" for="avatar-file">{{ auth.user?.avatar_url ? 'Zmień avatar' : 'Dodaj avatar' }}</label>
          <button v-if="auth.user?.avatar_url" type="button" class="link-button" :disabled="avatarForm.pending.value" data-test="avatar-remove" @click="removeAvatar">Usuń avatar</button>
        </div>
      </div>
      <p id="avatar-hint" class="field-hint">JPG albo PNG do 5 MB, co najmniej 128 × 128 px. Zapiszemy kwadratowy wycinek ze środka, bez danych o miejscu zrobienia zdjęcia.</p>
      <p v-if="avatarForm.pending.value" role="status">Wysyłanie…</p>
      <p v-if="avatarProblem || avatarForm.formError.value" role="alert" class="alert" data-test="avatar-error">
        {{ avatarProblem ?? avatarForm.errorFor('avatar') ?? avatarForm.formError.value }}
      </p>
    </section>

    <section class="stack narrow" aria-labelledby="password-heading">
      <h2 id="password-heading">Zmiana hasła</h2>
      <form class="stack" novalidate data-test="password-form" @submit.prevent="changePassword">
        <FormField id="current-password" v-model="currentPassword" label="Obecne hasło" type="password" autocomplete="current-password" required :error="passwordForm.errorFor('current_password')" />
        <FormField id="new-password" v-model="newPassword" label="Nowe hasło" type="password" autocomplete="new-password" required hint="Co najmniej 8 znaków, w tym litera i cyfra." :error="passwordForm.errorFor('password')" />
        <FormField id="new-password-confirmation" v-model="confirmation" label="Powtórz nowe hasło" type="password" autocomplete="new-password" required />
        <p v-if="passwordForm.formError.value" role="alert" class="alert" data-test="password-error">{{ passwordForm.formError.value }}</p>
        <p v-if="passwordMessage" role="status" data-test="password-changed">{{ passwordMessage }}</p>
        <button type="submit" :disabled="passwordForm.pending.value">{{ passwordForm.pending.value ? 'Zmienianie…' : 'Zmień hasło' }}</button>
      </form>
    </section>
  </div>
</template>
