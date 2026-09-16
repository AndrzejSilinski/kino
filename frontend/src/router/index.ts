/*
 * Trasy SPA. Ścieżki po angielsku, bo w Etapie 9 te same adresy obsłużą deep linki Fluttera
 * (np. /bookings/{reference}); teksty dla klienta są po polsku.
 *
 * Widoki ładowane leniwie (import()) — każdy ekran to osobny plik JS, a strona startowa
 * nie pobiera kodu planu sali ani Stripe'a.
 */
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { readRememberedCinema } from '@/stores/cinema';

export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { title: 'Wybierz kino' },
    // Zapamiętane kino (wymóg 3.1) otwiera od razu repertuar; ?change=1 pokazuje listę kin.
    beforeEnter: (to) => {
      const remembered = readRememberedCinema();
      return remembered && to.query.change === undefined ? { name: 'repertoire', params: { slug: remembered } } : true;
    },
  },
  {
    path: '/cinemas/:slug([a-z0-9-]+)',
    name: 'repertoire',
    component: () => import('@/views/RepertoireView.vue'),
    meta: { title: 'Repertuar' },
  },
  {
    path: '/screenings/:id(\\d+)/seats',
    name: 'screening-seats',
    component: () => import('@/views/ScreeningSeatsView.vue'),
    meta: { title: 'Wybór miejsc' },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: 'Logowanie', guestOnly: true },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { title: 'Załóż konto', guestOnly: true },
  },
  {
    // Blok J rozbuduje konto o historię zakupów, profil i ustawienia powiadomień.
    path: '/account',
    name: 'account',
    component: () => import('@/views/AccountView.vue'),
    meta: { title: 'Moje konto', requiresAuth: true },
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
