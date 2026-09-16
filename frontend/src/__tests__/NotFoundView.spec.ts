import { describe, expect, it } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router';
import NotFoundView from '@/views/NotFoundView.vue';

describe('NotFoundView', () => {
  it('pokazuje nagłówek i link powrotu do wyboru kina', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/zly-adres');
    const wrapper = mount(NotFoundView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.get('h1').text()).toBe('Nie znaleziono strony');
    expect(wrapper.get('a').attributes('href')).toBe('/');
  });
});
