/*
 * Odliczanie do wygaśnięcia blokad (Etap 8, blok F).
 *
 * Źródło: expires_in_seconds z odpowiedzi serwera + chwila jej odebrania na zegarze MONOTONICZNYM
 * (performance.now). Zegar ścienny urządzenia bywa przestawiony albo w złej strefie — różnica dat
 * dałaby zły wynik. Każdy takt liczy pozostały czas od nowa, więc opóźnione setInterval nie kumuluje błędu.
 *
 * performance.now() potrafi stanąć, gdy laptop śpi — dlatego po powrocie karty z tła właściciel
 * odlicznika odświeża koszyk z serwera (visibilitychange w widoku), a nie ufa lokalnemu licznikowi.
 */
import { computed, onScopeDispose, ref, watch, type Ref } from 'vue';

export interface Deadline {
  /** Sekundy do wygaśnięcia w chwili odebrania odpowiedzi. */
  seconds: number;
  /** performance.now() w chwili odebrania odpowiedzi. */
  receivedAt: number;
}

export interface CountdownOptions {
  now?: () => number;
  tickMs?: number;
  onExpire?: () => void;
}

export function remainingSeconds(deadline: Deadline | null, now: number): number | null {
  if (deadline === null) {
    return null;
  }
  return Math.max(0, Math.ceil(deadline.seconds - (now - deadline.receivedAt) / 1000));
}

export function formatClock(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

export function useCountdown(deadline: Ref<Deadline | null>, options: CountdownOptions = {}) {
  const now = options.now ?? (() => performance.now());
  const current = ref(now());
  let timer: ReturnType<typeof setInterval> | null = null;
  let expiredFor: Deadline | null = null;

  const remaining = computed(() => remainingSeconds(deadline.value, current.value));
  const label = computed(() => (remaining.value === null ? '' : formatClock(remaining.value)));

  function tick(): void {
    current.value = now();
    if (remaining.value === 0 && deadline.value !== null && expiredFor !== deadline.value) {
      expiredFor = deadline.value;
      options.onExpire?.();
    }
  }

  watch(deadline, (value) => {
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
    current.value = now();
    if (value !== null) {
      timer = setInterval(tick, options.tickMs ?? 250);
    }
  }, { immediate: true });

  onScopeDispose(() => {
    if (timer !== null) {
      clearInterval(timer);
    }
  });

  return { remaining, label, tick };
}
