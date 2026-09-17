/*
 * Obrazy kodów QR biletów (blok H4).
 *
 * <img src="qr_url"> nie zadziała: przeglądarka nie dołączy tokenu bearer, a podpisany adres w src
 * trafiłby do logów nginx razem z podpisem (decyzje 78 i 79). Pobieramy więc PNG przez fetch z tokenem
 * i pokazujemy przez adres obiektu (blob:, dozwolony w CSP img-src). Adresy obiektów trzymają obraz
 * w pamięci, dopóki ich nie zwolnimy — dlatego revokeObjectURL przy zmianie listy i przy wyjściu z ekranu.
 */
import { onScopeDispose, ref, shallowRef } from 'vue';
import type { Ticket } from '@/api/types';

export type QrState = { kind: 'loading' } | { kind: 'ready'; url: string } | { kind: 'error' };

export function useTicketQrs(fetchQr: (qrUrl: string, signal: AbortSignal) => Promise<Blob>) {
  const states = shallowRef<ReadonlyMap<number, QrState>>(new Map());
  const loading = ref(false);
  let controller: AbortController | null = null;
  const created: string[] = [];

  function revokeAll(): void {
    created.splice(0).forEach((url) => URL.revokeObjectURL(url));
  }

  function set(id: number, state: QrState): void {
    states.value = new Map(states.value).set(id, state);
  }

  /** Pobiera kody biletów ważnych i wykorzystanych; anulowany bilet nie ma kodu (serwer zwraca 409). */
  async function load(tickets: readonly Ticket[]): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    revokeAll();
    const wanted = tickets.filter((ticket) => ticket.status !== 'cancelled' && ticket.qr_url !== null);
    states.value = new Map(wanted.map((ticket) => [ticket.id, { kind: 'loading' } as QrState]));
    loading.value = true;
    await Promise.all(wanted.map(async (ticket) => {
      try {
        const blob = await fetchQr(ticket.qr_url as string, signal);
        if (signal.aborted) {
          return;
        }
        const url = URL.createObjectURL(blob);
        created.push(url);
        set(ticket.id, { kind: 'ready', url });
      } catch {
        if (!signal.aborted) {
          set(ticket.id, { kind: 'error' });
        }
      }
    }));
    if (!signal.aborted) {
      loading.value = false;
    }
  }

  onScopeDispose(() => {
    controller?.abort();
    revokeAll();
  });

  return { states, loading, load };
}
