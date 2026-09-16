import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ScreeningCalendar from '@/components/catalog/ScreeningCalendar.vue';
import { buildCalendar } from '@/lib/calendar';

describe('kalendarz (komponent)', () => {
  it('dzień bez seansów jest wyłączony, wybrany ma aria-pressed, kliknięcie zgłasza datę', async () => {
    const days = buildCalendar('2026-09-16', [
      { date: '2026-09-16', screenings_count: 1 },
      { date: '2026-09-18', screenings_count: 5 },
    ]);
    const wrapper = mount(ScreeningCalendar, { props: { days, selected: '2026-09-16' } });

    const today = wrapper.get('[data-date="2026-09-16"]');
    expect(today.attributes('aria-pressed')).toBe('true');
    expect(today.attributes('aria-label')).toBe('dziś, środa, 16 września: 1 seans');

    expect(wrapper.get('[data-date="2026-09-17"]').attributes('disabled')).toBeDefined();
    expect(wrapper.get('[data-date="2026-09-18"]').attributes('aria-label')).toBe('piątek, 18 września: 5 seansów');

    await wrapper.get('[data-date="2026-09-18"]').trigger('click');
    expect(wrapper.emitted('select')).toEqual([['2026-09-18']]);
  });
});
