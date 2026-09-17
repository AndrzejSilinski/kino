<script setup lang="ts">
/*
 * Ustawienia powiadomień (Etap 8, blok J, wymóg 3.4): zgoda na push i przypomnienia przed seansem.
 *
 * Przełącznik zapisuje się od razu (PATCH jednego pola) i BEZ optymizmu: do odpowiedzi serwera
 * jest wyłączony, a przy błędzie wraca do stanu z serwera — tak jak blokady miejsc na planie sali.
 * Zgoda na push jest zapisana na koncie; uprawnienie i rejestracja TEJ przeglądarki to osobny panel
 * (BrowserPushPanel, blok L). Włączenie w przeglądarce ustawia też zgodę — stąd ponowny odczyt.
 */
import { ref, shallowRef } from 'vue';
import { accountApi } from '@/api/client';
import BrowserPushPanel from '@/components/account/BrowserPushPanel.vue';
import type { NotificationSettings } from '@/api/types';
import { messageFor } from '@/messages';

type Field = 'push_enabled' | 'screening_reminders';

const settings = shallowRef<NotificationSettings | null>(null);
const loadError = ref<string | null>(null);
const saving = ref<Field | null>(null);
const saveError = ref<string | null>(null);

async function load(): Promise<void> {
  loadError.value = null;
  try {
    settings.value = await accountApi.notificationSettings();
  } catch (error) {
    loadError.value = messageFor(error);
  }
}

async function toggle(field: Field, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const wanted = input.checked;
  // Stan pola wraca do serwerowego, dopóki serwer nie potwierdzi zmiany.
  input.checked = settings.value?.[field] ?? false;
  if (!settings.value || saving.value) {
    return;
  }
  saving.value = field;
  saveError.value = null;
  try {
    settings.value = await accountApi.updateNotificationSettings({ [field]: wanted });
  } catch (error) {
    saveError.value = messageFor(error);
  } finally {
    saving.value = null;
  }
}

void load();
</script>

<template>
  <section class="stack narrow" aria-labelledby="notifications-heading">
    <h2 id="notifications-heading">Powiadomienia</h2>
    <p v-if="!settings && !loadError" role="status">Wczytywanie ustawień…</p>
    <div v-else-if="!settings" role="alert" class="stack">
      <p class="alert">{{ loadError }}</p>
      <button type="button" @click="load">Spróbuj ponownie</button>
    </div>
    <template v-else>
      <div class="setting">
        <input id="setting-reminders" type="checkbox" :checked="settings.screening_reminders" :disabled="saving !== null" :aria-busy="saving === 'screening_reminders'" data-test="setting-reminders" @change="toggle('screening_reminders', $event)" />
        <label for="setting-reminders">
          <strong>Przypomnienia przed seansem</strong>
          <span class="field-hint">E-mail na około 2 godziny przed seansem, a przy zgodzie na push — także powiadomienie.</span>
        </label>
      </div>
      <div class="setting">
        <input id="setting-push" type="checkbox" :checked="settings.push_enabled" :disabled="saving !== null" :aria-busy="saving === 'push_enabled'" data-test="setting-push" @change="toggle('push_enabled', $event)" />
        <label for="setting-push">
          <strong>Zgoda na powiadomienia push</strong>
          <span class="field-hint">Potwierdzenie płatności i przypomnienia na urządzeniach, na których włączysz powiadomienia. Treść nie zawiera danych osobowych — tylko film i godzinę seansu.</span>
        </label>
      </div>
      <p v-if="saving" role="status">Zapisywanie…</p>
      <p v-if="saveError" role="alert" class="alert" data-test="settings-error">{{ saveError }}</p>
      <BrowserPushPanel @changed="load" />
    </template>
  </section>
</template>
