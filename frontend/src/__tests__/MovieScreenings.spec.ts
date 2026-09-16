import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import MovieScreenings from '@/components/catalog/MovieScreenings.vue';
import { groupByMovie } from '@/lib/repertoire';
import { routes } from '@/router';
import { screening } from './fixtures/catalog';

describe('karta filmu z godzinami seansów', () => {
  it('seans do kupienia jest linkiem do planu sali, wyprzedany jest wyszarzony i NIE jest linkiem', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    const [group] = groupByMovie([
      screening({ id: 10, starts_at: '2026-09-17T11:00:00+02:00' }),
      screening({ id: 11, starts_at: '2026-09-17T20:15:00+02:00', is_sold_out: true, is_bookable: false, seats: { total: 90, taken: 90, available: 0 } }),
    ]);
    const wrapper = mount(MovieScreenings, { props: { group: group! }, global: { plugins: [router] } });

    const open = wrapper.get('[data-test="showtime-10"]');
    expect(open.element.tagName).toBe('A');
    expect(open.attributes('href')).toBe('/screenings/10/seats');
    expect(open.text()).toContain('11:00');

    const soldOut = wrapper.get('[data-test="showtime-11"]');
    expect(soldOut.element.tagName).toBe('SPAN');
    expect(soldOut.attributes('aria-disabled')).toBe('true');
    expect(soldOut.classes()).toContain('is-disabled');
    expect(soldOut.find('s').text()).toBe('20:15');
    expect(soldOut.text()).toContain('Wyprzedane');
  });

  it('bez plakatu pokazuje zaślepkę z inicjałami ukrytą przed czytnikiem ekranu', () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    const [group] = groupByMovie([screening({ title: 'Diuna: Część druga' })]);
    const wrapper = mount(MovieScreenings, { props: { group: group! }, global: { plugins: [router] } });

    expect(wrapper.get('.poster-placeholder').text()).toBe('DC');
    expect(wrapper.get('.poster-placeholder').attributes('aria-hidden')).toBe('true');
    expect(wrapper.get('h2').text()).toBe('Diuna: Część druga');
  });
});
