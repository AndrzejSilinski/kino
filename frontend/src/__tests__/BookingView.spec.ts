import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';
import type { Ticket } from '@/api/types';
import { booking, REFERENCE } from './fixtures/checkout';
import { screeningDetails } from './fixtures/seats';

const bookings = vi.hoisted(() => ({ show: vi.fn(), ticketQr: vi.fn(), ticketsPdf: vi.fn() }));
vi.mock('@/api/client', () => ({ bookingsApi: bookings }));
const saveBlob = vi.hoisted(() => vi.fn());
vi.mock('@/lib/download', () => ({ saveBlob }));

const { routes } = await import('@/router');
const { default: BookingView } = await import('@/views/BookingView.vue');
const { prices: _prices, ...screening } = screeningDetails;

const ticket = (id: number, status: Ticket['status'] = 'valid'): Ticket => ({
  id,
  price: { amount: 1760, currency: 'PLN', formatted: '17,60 zł' },
  status,
  status_label: { valid: 'Ważny', used: 'Wykorzystany', cancelled: 'Anulowany' }[status],
  validated_at: null,
  qr_url: `http://localhost:3000/api/v1/bookings/${REFERENCE}/tickets/${id}/qr`,
  seat: { id: 890 + id, row: 'A', number: id, label: `A${id}`, type: 'standard' },
});

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(`/bookings/${REFERENCE}`);
  const wrapper = mount(BookingView, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

describe('szczegóły rezerwacji z biletami', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    let counter = 0;
    URL.createObjectURL = vi.fn(() => `blob:qr-${++counter}`);
    URL.revokeObjectURL = vi.fn();
  });
  afterEach(() => vi.restoreAllMocks());

  it('opłacona: bilet z miejscem, ceną, statusem i kodem QR z pobranego obrazu (blob:), z opisem alternatywnym', async () => {
    bookings.show.mockResolvedValue(booking({ screening, status: 'paid', status_label: 'Opłacona', tickets: [ticket(1), ticket(2, 'used')] }));
    bookings.ticketQr.mockResolvedValue(new Blob(['png']));
    const wrapper = await mountView();

    expect(bookings.ticketQr).toHaveBeenCalledWith(ticket(1).qr_url, expect.any(AbortSignal));
    const img = wrapper.get('[data-test="ticket-1"] img');
    expect(img.attributes('src')).toMatch(/^blob:qr-/);
    expect(img.attributes('alt')).toBe('Kod QR biletu: rząd a, miejsce 1');
    expect(wrapper.get('[data-test="ticket-2"]').text()).toContain('Wykorzystany');
    expect(wrapper.get('h1').text()).toBe('Barbie');
  });

  it('PDF: pobranie przez fetch z tokenem i zapis pod nazwą z Content-Disposition', async () => {
    bookings.show.mockResolvedValue(booking({ screening, status: 'paid', status_label: 'Opłacona', tickets: [ticket(1)] }));
    bookings.ticketQr.mockResolvedValue(new Blob(['png']));
    const pdf = new Blob(['%PDF'], { type: 'application/pdf' });
    bookings.ticketsPdf.mockResolvedValue({ blob: pdf, filename: `bilety-${REFERENCE}.pdf`, headers: new Headers() });
    const wrapper = await mountView();

    await wrapper.get('[data-test="download-pdf"]').trigger('click');
    await flushPromises();

    expect(bookings.ticketsPdf).toHaveBeenCalledWith(REFERENCE);
    expect(saveBlob).toHaveBeenCalledWith(pdf, `bilety-${REFERENCE}.pdf`);
  });

  it('limit pobrań (429): komunikat przy przycisku PDF, bez zapisu pliku', async () => {
    bookings.show.mockResolvedValue(booking({ screening, status: 'paid', status_label: 'Opłacona', tickets: [ticket(1)] }));
    bookings.ticketQr.mockResolvedValue(new Blob(['png']));
    bookings.ticketsPdf.mockRejectedValue(new ApiError({ status: 429, code: 'TOO_MANY_REQUESTS', message: 'x', retryAfterSeconds: 42 }));
    const wrapper = await mountView();

    await wrapper.get('[data-test="download-pdf"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-test="pdf-error"]').text()).toBe('Zbyt wiele prób. Spróbuj ponownie za 42 s.');
    expect(saveBlob).not.toHaveBeenCalled();
  });

  it('nieopłacona: bez żądań o kody QR i PDF (serwer i tak by odmówił)', async () => {
    bookings.show.mockResolvedValue(booking({ screening, tickets: [] }));
    const wrapper = await mountView();

    expect(wrapper.get('[data-test="tickets-unavailable"]').text()).toContain('czeka na płatność');
    expect(bookings.ticketQr).not.toHaveBeenCalled();
    expect(wrapper.find('[data-test="download-pdf"]').exists()).toBe(false);
  });

  it('cudza albo nieistniejąca rezerwacja: jeden komunikat', async () => {
    bookings.show.mockRejectedValue(new ApiError({ status: 404, code: 'RESOURCE_NOT_FOUND', message: 'x' }));
    const wrapper = await mountView();

    expect(wrapper.get('h1').text()).toBe('Nie znaleziono rezerwacji');
  });
});
