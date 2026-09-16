import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RealtimeBanner from '@/components/realtime/RealtimeBanner.vue';

describe('baner aktualizacji na żywo', () => {
  it('na żywo: krótka informacja; offline: komunikat i ręczne odświeżenie zamiast odpytywania', async () => {
    const live = mount(RealtimeBanner, { props: { status: 'live', refreshing: false } });
    expect(live.find('[data-test="realtime-live"]').exists()).toBe(true);
    expect(live.find('button').exists()).toBe(false);

    const offline = mount(RealtimeBanner, { props: { status: 'offline', refreshing: false } });
    expect(offline.get('[data-test="realtime-offline"]').text()).toContain('Brak połączenia na żywo');
    await offline.get('button').trigger('click');
    expect(offline.emitted('refresh')).toHaveLength(1);
  });
});
