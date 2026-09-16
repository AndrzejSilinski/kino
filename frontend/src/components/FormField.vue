<script setup lang="ts">
/*
 * Pole formularza z etykietą i komunikatem błędu (Etap 8, blok D).
 * Dostępność: <label for>, aria-invalid i aria-describedby wskazujące komunikat,
 * więc czytnik ekranu przeczyta błąd razem z polem.
 */
import { computed } from 'vue';

const props = withDefaults(defineProps<{
  id: string;
  label: string;
  type?: string;
  autocomplete?: string;
  required?: boolean;
  error?: string;
  hint?: string;
}>(), {
  type: 'text',
  autocomplete: 'off',
  required: false,
  error: undefined,
  hint: undefined,
});

const model = defineModel<string>({ required: true });

const describedBy = computed(() => [props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : '']
  .filter(Boolean).join(' ') || undefined);
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :type="type"
      :name="id"
      :autocomplete="autocomplete"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
    />
    <p v-if="hint" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
  </div>
</template>
