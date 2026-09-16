/*
 * Trasy SPA. Ścieżki po angielsku, bo w Etapie 9 te same adresy obsłużą deep linki Fluttera
 * (np. /bookings/{reference}); teksty dla klienta są po polsku.
 *
 * Widoki ładowane leniwie (import()) — każdy ekran to osobny plik JS, a strona startowa
 * nie pobiera kodu planu sali ani Stripe'a.
 */
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { title: 'Wybierz kino' },
  },
  {
    // Każdy nieznany adres. nginx zwraca index.html dla wszystkich ścieżek SPA,
    // więc o "nie ma takiej strony" rozstrzyga router, a nie serwer.
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Nie znaleziono strony' },
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.afterEach((to) => {
  const title = typeof to.meta.title === 'string' ? to.meta.title : '';
  document.title = title ? `${title} · Kino` : 'Kino — bilety online';
});
