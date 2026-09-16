/*
 * "Tylko najnowsza odpowiedź wygrywa" (Etap 8, blok E).
 *
 * Klient szybko klika wtorek, potem środę. Odpowiedź dla wtorku może przyjść PO środzie
 * i nadpisać repertuar — ekran pokazywałby środę w kalendarzu i wtorek w kartach.
 * Każde nowe żądanie przerywa poprzednie (AbortController), a wynik starszego jest ignorowany
 * nawet wtedy, gdy przyszedł mimo przerwania.
 */
import { onScopeDispose, ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import { messageFor } from '@/messages';

export interface LatestRequest<T> {
  data: ShallowRef<T | null>;
  error: Ref<unknown>;
  errorMessage: Ref<string | null>;
  loading: Ref<boolean>;
  run(loader: (signal: AbortSignal) => Promise<T>): Promise<void>;
  /** Przerywa bieżące żądanie i czyści dane (np. zmiana kina — bez pokazywania poprzedniego). */
  reset(): void;
}

export function useLatestRequest<T>(): LatestRequest<T> {
  const data = shallowRef<T | null>(null);
  const error = ref<unknown>(null);
  const errorMessage = ref<string | null>(null);
  const loading = ref(false);
  let current: AbortController | null = null;
  let sequence = 0;

  async function run(loader: (signal: AbortSignal) => Promise<T>): Promise<void> {
    current?.abort();
    const controller = new AbortController();
    current = controller;
    const id = ++sequence;
    loading.value = true;
    error.value = null;
    errorMessage.value = null;

    try {
      const result = await loader(controller.signal);
      if (id === sequence) {
        data.value = result;
      }
    } catch (caught) {
      if (id === sequence && !(caught instanceof DOMException && caught.name === 'AbortError')) {
        // Po błędzie nie zostawiamy danych z poprzedniego żądania: ekran nie może mieszać
        // nagłówka nowego kina z repertuarem starego.
        data.value = null;
        error.value = caught;
        errorMessage.value = messageFor(caught);
      }
    } finally {
      if (id === sequence) {
        loading.value = false;
      }
    }
  }

  function reset(): void {
    current?.abort();
    sequence++;
    data.value = null;
    error.value = null;
    errorMessage.value = null;
    loading.value = false;
  }

  onScopeDispose(() => current?.abort());

  return { data, error, errorMessage, loading, run, reset };
}
