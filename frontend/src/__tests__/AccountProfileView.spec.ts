import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/api/errors';
import { AVATAR_URL, user } from './fixtures/account';

const account = vi.hoisted(() => ({ updateProfile: vi.fn(), changePassword: vi.fn(), uploadAvatar: vi.fn(), deleteAvatar: vi.fn() }));
vi.mock('@/api/client', () => ({ accountApi: account, authApi: {} }));

const { default: AccountProfileView } = await import('@/views/account/AccountProfileView.vue');
const { useAuthStore } = await import('@/stores/auth');

function mountView() {
  const auth = useAuthStore();
  auth.token = 'token-testowy';
  auth.user = user();
  return { wrapper: mount(AccountProfileView), auth };
}

function selectFile(wrapper: ReturnType<typeof mount>, file: File) {
  const input = wrapper.get('[data-test="avatar-input"]');
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
  return input.trigger('change');
}

describe('profil i hasło', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    setActivePinia(createPinia());
  });

  it('zmiana imienia trafia do sesji (nagłówek widzi nowe imię od razu)', async () => {
    account.updateProfile.mockResolvedValue(user({ name: 'Anna Kowalska' }));
    const { wrapper, auth } = mountView();

    await wrapper.get('#profile-name').setValue('Anna Kowalska');
    await wrapper.get('[data-test="profile-form"]').trigger('submit');
    await flushPromises();

    expect(account.updateProfile).toHaveBeenCalledWith('Anna Kowalska');
    expect(auth.user?.name).toBe('Anna Kowalska');
    expect(wrapper.find('[data-test="profile-saved"]').exists()).toBe(true);
  });

  it('avatar: poprawny plik -> upload i nowy obraz; za duży — komunikat bez wysyłania', async () => {
    account.uploadAvatar.mockResolvedValue(user({ avatar_url: AVATAR_URL }));
    const { wrapper } = mountView();

    await selectFile(wrapper, new File([new Uint8Array(6 * 1024 * 1024)], 'duzy.png', { type: 'image/png' }));
    await flushPromises();
    expect(account.uploadAvatar).not.toHaveBeenCalled();
    expect(wrapper.get('[data-test="avatar-error"]').text()).toBe('Avatar nie może być większy niż 5 MB.');

    const file = new File(['png'], 'ja.png', { type: 'image/png' });
    await selectFile(wrapper, file);
    await flushPromises();
    expect(account.uploadAvatar).toHaveBeenCalledWith(file);
    expect(wrapper.get('[data-test="avatar-image"]').attributes('src')).toBe(AVATAR_URL);
    expect(wrapper.find('[data-test="avatar-error"]').exists()).toBe(false);
  });

  it('avatar odrzucony przez serwer (za mały obraz): komunikat serwera przy avatarze', async () => {
    account.uploadAvatar.mockRejectedValue(new ApiError({ status: 422, code: 'AVATAR_INVALID', message: 'Obraz ma 64 × 64 px, a musi mieć co najmniej 128 × 128 px.', context: { reason: 'too_small' } }));
    const { wrapper } = mountView();

    await selectFile(wrapper, new File(['png'], 'maly.png', { type: 'image/png' }));
    await flushPromises();

    expect(wrapper.get('[data-test="avatar-error"]').text()).toBe('Obraz ma 64 × 64 px, a musi mieć co najmniej 128 × 128 px.');
  });

  it('zmiana hasła: błąd obecnego hasła przy polu, sukces czyści pola i mówi o wylogowanych urządzeniach', async () => {
    account.changePassword
      .mockRejectedValueOnce(new ApiError({ status: 422, code: 'VALIDATION_FAILED', message: 'x', errors: { current_password: ['Obecne hasło jest nieprawidłowe.'] } }))
      .mockResolvedValue({ message: 'Hasło zostało zmienione.', revoked_tokens: 2 });
    const { wrapper } = mountView();

    await wrapper.get('#current-password').setValue('zle12345');
    await wrapper.get('#new-password').setValue('nowe12345');
    await wrapper.get('#new-password-confirmation').setValue('nowe12345');
    await wrapper.get('[data-test="password-form"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('#current-password-error').text()).toBe('Obecne hasło jest nieprawidłowe.');
    expect(wrapper.get('#current-password').attributes('aria-invalid')).toBe('true');

    await wrapper.get('#current-password').setValue('dobre1234');
    await wrapper.get('[data-test="password-form"]').trigger('submit');
    await flushPromises();

    expect(account.changePassword).toHaveBeenLastCalledWith({ current_password: 'dobre1234', password: 'nowe12345', password_confirmation: 'nowe12345' });
    expect(wrapper.get('[data-test="password-changed"]').text()).toBe('Hasło zostało zmienione. Wylogowaliśmy pozostałe urządzenia (2).');
    expect((wrapper.get('#current-password').element as HTMLInputElement).value).toBe('');
  });
});
