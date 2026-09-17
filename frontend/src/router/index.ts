/*
 * Trasy SPA. Ścieżki po angielsku, bo w Etapie 9 te same adresy obsłużą deep linki Fluttera
 * (np. /bookings/{reference}); teksty dla klienta są po polsku.
 *
 * Widoki ładowane leniwie (import()) — każdy ekran to osobny plik JS, a strona startowa
 * nie pobiera kodu planu sali ani Stripe'a.
 */
import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteLocationRaw, type RouteRecordRaw } from 'vue-router';
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
    // Podsumowanie i płatność (blok H2). Wymaga konta: rezerwacja ma właściciela. Koszyk przetrwa
    // logowanie, bo sesja zakupowa jest w sessionStorage karty, a nie w koncie.
    path: '/screenings/:id(\\d+)/checkout',
    name: 'checkout',
    component: () => import('@/views/CheckoutView.vue'),
    meta: { title: 'Podsumowanie i płatność', requiresAuth: true },
  },
  {
    // Szczegóły rezerwacji z kodami QR i PDF (blok H4); adres deep linku aplikacji mobilnej (Etap 9).
    path: '/bookings/:reference([0-9A-Z]{26})',
    name: 'booking',
    component: () => import('@/views/BookingView.vue'),
    meta: { title: 'Rezerwacja', requiresAuth: true },
  },
  {
    // Wynik płatności (blok H3): return_url Stripe'a i cel po potwierdzeniu w formularzu.
    path: '/bookings/:reference([0-9A-Z]{26})/payment-result',
    name: 'payment-result',
    component: () => import('@/views/PaymentResultView.vue'),
    meta: { title: 'Wynik płatności', requiresAuth: true },
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

/** Parametry dopisywane przez Stripe do return_url; client_secret pozwala dokończyć cudzą płatność. */
export const STRIPE_RETURN_PARAMS = ['payment_intent', 'payment_intent_client_secret', 'setup_intent', 'setup_intent_client_secret'];

/**
 * Usuwa z adresu parametry powrotu ze Stripe'a, zanim cokolwiek je zobaczy (blok H3).
 * Strażnik globalny, zarejestrowany PRZED strażnikiem logowania z main.ts: inaczej wygasła sesja
 * przepisałaby pełny adres z client_secret do ?redirect= strony logowania, a stamtąd do historii.
 * redirect_status zostaje — nie jest tajny, a poprawia komunikat ekranu wyniku.
 */
export function stripStripeReturnParams(to: Pick<RouteLocationNormalized, 'path' | 'query' | 'hash'>): RouteLocationRaw | true {
  if (!STRIPE_RETURN_PARAMS.some((name) => name in to.query)) {
    return true;
  }
  const query = Object.fromEntries(Object.entries(to.query).filter(([name]) => !STRIPE_RETURN_PARAMS.includes(name)));
  return { path: to.path, query, hash: to.hash, replace: true };
}

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(stripStripeReturnParams);

router.afterEach((to) => {
  const title = typeof to.meta.title === 'string' ? to.meta.title : '';
  document.title = title ? `${title} · Kino` : 'Kino — bilety online';
});
