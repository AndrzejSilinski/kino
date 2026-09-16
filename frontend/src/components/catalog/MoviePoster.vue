<script setup lang="ts">
/* Plakat albo zaślepka z inicjałami (dane z seedera nie mają plakatów). Plakat jest dekoracją:
 * tytuł jest obok w nagłówku karty, więc alt="" i zaślepka aria-hidden — czytnik nie powtarza tytułu. */
import { computed, ref } from 'vue';

const props = defineProps<{ url: string | null; title: string }>();
const failed = ref(false);
const initials = computed(() => props.title.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]?.toUpperCase()).join(''));
</script>

<template>
  <img v-if="url && !failed" :src="url" alt="" class="poster" loading="lazy" width="120" height="180" @error="failed = true" />
  <div v-else class="poster poster-placeholder" aria-hidden="true">{{ initials }}</div>
</template>
