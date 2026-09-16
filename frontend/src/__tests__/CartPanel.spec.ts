import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CartPanel from '@/components/seats/CartPanel.vue';
import { cartOf } from './fixtures/seats';

describe('panel wybranych miejsc', () => {
  it('pokazuje rząd, numer, cenę jednostkową i sumę sformatowane przez serwer', async () => {
    const wrapper = mount(CartPanel, { props: { cart: cartOf([891, 895]), maxSeats: 10, busy: false } });

    expect(wrapper.get('[data-test="cart-seat-891"]').text()).toContain('Rząd A, miejsce 1');
    expect(wrapper.get('[data-test="cart-seat-895"]').text()).toContain('17,60 zł');
    expect(wrapper.get('[data-test="cart-total"]').text()).toBe('35,20 zł');
    expect(wrapper.text()).toContain('2 z 10');

    await wrapper.get('button').trigger('click');
    expect(wrapper.emitted('clear')).toHaveLength(1);
  });

  it('pusty koszyk ma czytelny komunikat', () => {
    const wrapper = mount(CartPanel, { props: { cart: cartOf([]), maxSeats: 10, busy: false } });

    expect(wrapper.text()).toContain('Nie wybrano jeszcze miejsc.');
  });
});
