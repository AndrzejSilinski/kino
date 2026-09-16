import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SeatMap from '@/components/seats/SeatMap.vue';
import { buildSeatLayout } from '@/lib/seatLayout';
import { mapSeat } from './fixtures/seats';

function render(own: number[] = [], pending: number[] = []) {
  const layout = buildSeatLayout([
    mapSeat({ id: 1, position: { x: 1, y: 1 } }),
    mapSeat({ id: 2, position: { x: 2, y: 1 }, status: 'sold' }),
    mapSeat({ id: 3, position: { x: 4, y: 1 }, type: 'double', number: 3 }),
    mapSeat({ id: 4, position: { x: 1, y: 2 }, row: 'B', type: 'accessible' }),
  ], { rows: 2, columns: 6 });
  return mount(SeatMap, { props: { layout, ownSeatIds: new Set(own), pendingSeatIds: new Set(pending) } });
}

describe('plan sali (komponent)', () => {
  it('rysuje rzędy jako grupy, miejsce podwójne na dwóch kratkach, stany nie tylko kolorem', () => {
    const wrapper = render([1]);

    expect(wrapper.findAll('[role="group"][aria-label^="Rząd"]').map((row) => row.attributes('aria-label'))).toEqual(['Rząd A', 'Rząd B']);
    expect(wrapper.get('[data-seat-id="3"]').element.parentElement?.getAttribute('style')).toContain('grid-column: 5 / span 2');

    const selected = wrapper.get('[data-seat-id="1"]');
    expect(selected.attributes('aria-pressed')).toBe('true');
    expect(selected.text()).toBe('✓');

    const sold = wrapper.get('[data-seat-id="2"]');
    expect(sold.attributes('disabled')).toBeDefined();
    expect(sold.text()).toBe('✕');
    expect(sold.attributes('aria-label')).toBe('Rząd A, miejsce 2, sprzedane, Standardowe, 17,60 zł');

    expect(wrapper.get('[data-seat-id="4"]').text()).toBe('♿');
  });

  it('miejsce oczekujące na serwer jest zablokowane z aria-busy; kliknięcie wolnego zgłasza toggle', async () => {
    const wrapper = render([], [3]);

    const busy = wrapper.get('[data-seat-id="3"]');
    expect(busy.attributes('aria-busy')).toBe('true');
    expect(busy.attributes('disabled')).toBeDefined();

    await wrapper.get('[data-seat-id="1"]').trigger('click');
    expect(wrapper.emitted('toggle')).toEqual([[1]]);
  });
});
