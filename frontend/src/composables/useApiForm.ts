/*
 * Stan wysyłania formularza do API (Etap 8, blok D): blokada podwójnego wysłania,
 * błędy pól z 422 i jeden komunikat ogólny dla pozostałych kodów.
 * Widok tylko wyświetla; decyzja "pole czy komunikat ogólny" zapada tutaj, po `code`.
 */
import { ref } from 'vue';
import { isApiError } from '@/api/errors';
import { fieldError, messageFor } from '@/messages';

export function useApiForm() {
  const pending = ref(false);
  const fieldErrors = ref<Record<string, string[]>>({});
  const formError = ref<string | null>(null);

  async function submit(action: () => Promise<void>): Promise<boolean> {
    if (pending.value) {
      return false;
    }
    pending.value = true;
    fieldErrors.value = {};
    formError.value = null;
    try {
      await action();
      return true;
    } catch (error) {
      if (isApiError(error) && error.code === 'VALIDATION_FAILED') {
        fieldErrors.value = error.errors;
        formError.value = 'Popraw zaznaczone pola.';
      } else {
        formError.value = messageFor(error);
      }
      return false;
    } finally {
      pending.value = false;
    }
  }

  const errorFor = (field: string): string | undefined => fieldError(fieldErrors.value, field);

  return { pending, fieldErrors, formError, submit, errorFor };
}
