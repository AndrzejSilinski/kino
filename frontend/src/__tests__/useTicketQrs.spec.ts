import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import type { Ticket } from '@/api/types';
import { useTicketQrs } from '@/composables/useTicketQrs';

const ticket = (id: number, status: Ticket['status'] = 'valid'): Ticket => ({
  id,
  price: { amount: 1760, currency: 'PLN', formatted: '17,60 zł' },
  status,
  status_label: status,
  validated_at: null,
  qr_url: `http://localhost:3000/api/v1/bookings/X/tickets/${id}/qr`,
  seat: { id: 890 + id, row: 'A', number: id, label: `A${id}`, type: 'standard' },
});

describe('kody QR biletów', () => {
  let counter = 0;
  beforeEach(() => {
    counter = 0;
    vi.stubGlobal('URL', Object.assign(URL, {
      createObjectURL: vi.fn(() => `blob:qr-${++counter}`),
      revokeObjectURL: vi.fn(),
    }));
  });
  afterEach(() => vi.unstubAllGlobals());

  it('pobiera obrazy tylko biletów, które mają kod; anulowany bilet pomija', async () => {
    const fetchQr = vi.fn(async () => new Blob(['png']));
    const scope = effectScope();
    const qrs = scope.run(() => useTicketQrs(fetchQr))!;

    await qrs.load([ticket(1), ticket(2, 'used'), ticket(3, 'cancelled')]);

    expect(fetchQr).toHaveBeenCalledTimes(2);
    expect(qrs.states.value.get(1)).toEqual({ kind: 'ready', url: 'blob:qr-1' });
    expect(qrs.states.value.get(2)).toEqual({ kind: 'ready', url: 'blob:qr-2' });
    expect(qrs.states.value.has(3)).toBe(false);
    expect(qrs.loading.value).toBe(false);
    scope.stop();
  });

  it('błąd jednego obrazu nie psuje pozostałych', async () => {
    const fetchQr = vi.fn(async (url: string) => {
      if (url.includes('/tickets/2/')) throw new Error('429');
      return new Blob(['png']);
    });
    const scope = effectScope();
    const qrs = scope.run(() => useTicketQrs(fetchQr))!;

    await qrs.load([ticket(1), ticket(2)]);

    expect(qrs.states.value.get(1)?.kind).toBe('ready');
    expect(qrs.states.value.get(2)).toEqual({ kind: 'error' });
    scope.stop();
  });

  it('adresy obiektów są zwalniane przy ponownym wczytaniu i przy wyjściu z ekranu (bez wycieku pamięci)', async () => {
    const fetchQr = vi.fn(async () => new Blob(['png']));
    const scope = effectScope();
    const qrs = scope.run(() => useTicketQrs(fetchQr))!;

    await qrs.load([ticket(1)]);
    await qrs.load([ticket(1)]);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:qr-1');

    scope.stop();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:qr-2');
  });
});
