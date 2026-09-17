import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router';

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

  it('każdy nieznany adres obsługuje ekran 404 (nginx oddaje index.html dla wszystkich ścieżek SPA)', async () => {
    const router = makeRouter();
    await router.push('/nie/ma/takiej/strony?x=1');

    expect(router.currentRoute.value.name).toBe('not-found');
  });
});
