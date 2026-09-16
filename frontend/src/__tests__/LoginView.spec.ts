import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@/api/errors';

const api = vi.hoisted(() => ({ login: vi.fn(), register: vi.fn(), logout: vi.fn(), me: vi.fn() }));
vi.mock('@/api/client', () => ({ authApi: api }));

const { routes } = await import('@/router');
const { default: LoginView } = await import('@/views/LoginView.vue');

async function mountAt(url: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(url);
  const wrapper = mount(LoginView, { global: { plugins: [router] } });
  return { wrapper, router };
}

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper']) {
  await wrapper.get('#email').setValue('anna@example.com');
  await wrapper.get('#password').setValue('haslo1234');
  await wrapper.get('form').trigger('submit');
  await flushPromises();
}

describe('ekran logowania', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('błędy walidacji (422) pokazuje przy polach z aria-invalid', async () => {
    api.login.mockRejectedValue(new ApiError({ status: 422, code: 'VALIDATION_FAILED', message: 'x', errors: { email: ['Adres e-mail jest nieprawidłowy.'] } }));
    const { wrapper } = await mountAt('/login');

    await fillAndSubmit(wrapper);

    expect(wrapper.get('#email').attributes('aria-invalid')).toBe('true');
    expect(wrapper.get('#email-error').text()).toBe('Adres e-mail jest nieprawidłowy.');
    expect(wrapper.get('#email').attributes('aria-describedby')).toContain('email-error');
  });

  it('INVALID_CREDENTIALS pokazuje komunikat serwera w alercie formularza', async () => {
    api.login.mockRejectedValue(new ApiError({ status: 401, code: 'INVALID_CREDENTIALS', message: 'Nieprawidłowy adres e-mail lub hasło.' }));
    const { wrapper } = await mountAt('/login');

    await fillAndSubmit(wrapper);

    expect(wrapper.get('[data-test="form-error"]').attributes('role')).toBe('alert');
    expect(wrapper.get('[data-test="form-error"]').text()).toBe('Nieprawidłowy adres e-mail lub hasło.');
  });

  it('po zalogowaniu przechodzi pod adres z ?redirect=, a przycisk jest zablokowany w trakcie', async () => {
    let finish!: (value: unknown) => void;
    api.login.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const { wrapper, router } = await mountAt('/login?redirect=/account');

    await wrapper.get('#email').setValue('anna@example.com');
    await wrapper.get('#password').setValue('haslo1234');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

    finish({ user: { id: 1, name: 'Anna', email: 'anna@example.com', role: 'customer', role_label: 'Klient', created_at: null }, token: '9|t', token_type: 'Bearer' });
    await flushPromises();

    // Trasa /account ładuje widok leniwie (import()), więc nawigacja kończy się po kilku tickach.
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/account'));
  });
});
