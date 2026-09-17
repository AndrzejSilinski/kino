import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes, stripStripeReturnParams } from '@/router';

const makeRouter = () => createRouter({ history: createMemoryHistory(), routes });

describe('router', () => {
  it('prowadzi adres główny do ekranu startowego', async () => {
    const router = makeRouter();
    await router.push('/');

    expect(router.currentRoute.value.name).toBe('home');
  });

  it('checkout seansu wymaga konta (rezerwacja ma właściciela), plan sali — nie', async () => {
    const router = makeRouter();

    expect(router.resolve('/screenings/334/checkout')).toMatchObject({ name: 'checkout', meta: { requiresAuth: true } });
    expect(router.resolve('/screenings/334/seats').meta.requiresAuth).toBeUndefined();
  });

  it('parametry powrotu ze Stripe\'a (z client_secret) znikają z adresu, redirect_status zostaje', () => {
    const router = makeRouter();
    const secret = ['pi', 'atrapa', 'secret', 'atrapa'].join('_');
    const to = router.resolve(`/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/payment-result?payment_intent=pi_atrapa&payment_intent_client_secret=${secret}&redirect_status=failed`);

    expect(stripStripeReturnParams(to)).toEqual({ path: '/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/payment-result', query: { redirect_status: 'failed' }, hash: '', replace: true });
    expect(stripStripeReturnParams(router.resolve('/screenings/334/seats?x=1'))).toBe(true);
  });

  it('wynik płatności wymaga konta i przyjmuje tylko numer rezerwacji w formacie ULID', () => {
    const router = makeRouter();

    expect(router.resolve('/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA/payment-result')).toMatchObject({ name: 'payment-result', meta: { requiresAuth: true } });
    expect(router.resolve('/bookings/123/payment-result').name).toBe('not-found');
  });

  it('podstrony konta mają własne adresy i dziedziczą wymóg zalogowania po rodzicu', () => {
    const router = makeRouter();

    expect(router.resolve('/account')).toMatchObject({ name: 'account', meta: { requiresAuth: true } });
    expect(router.resolve('/account/profile')).toMatchObject({ name: 'account-profile', meta: { requiresAuth: true } });
    expect(router.resolve('/account/notifications')).toMatchObject({ name: 'account-notifications', meta: { requiresAuth: true } });
  });

  it('artykuły publiczne; slug tylko z małych liter, cyfr i myślników', () => {
    const router = makeRouter();

    expect(router.resolve('/news')).toMatchObject({ name: 'articles' });
    expect(router.resolve('/news/premiera-diuny-2026')).toMatchObject({ name: 'article', meta: {} });
    expect(router.resolve('/news/premiera-diuny').meta.requiresAuth).toBeUndefined();
    expect(router.resolve('/news/Zly_Slug').name).toBe('not-found');
  });

  it('każdy nieznany adres obsługuje ekran 404 (nginx oddaje index.html dla wszystkich ścieżek SPA)', async () => {
    const router = makeRouter();
    await router.push('/nie/ma/takiej/strony?x=1');

    expect(router.currentRoute.value.name).toBe('not-found');
  });
});
