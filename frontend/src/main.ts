/*
 * Punkt wejścia SPA (Etap 8).
 *
 * Kolejność: Pinia -> klient HTTP z dostępem do tokenu -> strażnicy tras -> router -> montaż.
 * Strażnik czeka na auth.init() (odczyt /auth/me przy zapisanym tokenie), więc pierwszy ekran
 * nie mignie jako "gość" u zalogowanego użytkownika.
 */
import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { configureHttp } from './api/client';
import { router } from './router';
import { installAuthGuards } from './router/guards';
import { useAuthStore } from './stores/auth';
import { useWebPushStore } from './stores/webPush';
import './styles/base.css';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);

const auth = useAuthStore(pinia);

configureHttp({
  getToken: () => auth.token,
  // Token odrzucony przez API: czyścimy sesję; przekierowanie robi obserwator w installAuthGuards.
  onUnauthorized: () => auth.clearSession(),
});

window.addEventListener('storage', (event) => auth.syncFromStorage(event));

installAuthGuards(router, auth);

// Blok L: wylogowanie (także w innej karcie albo po wygaśnięciu tokenu) — ta przeglądarka przestaje
// odbierać push. Urządzenie na serwerze usuwa już kaskada przy tokenie Sanctum.
const webPush = useWebPushStore(pinia);
watch(() => auth.isAuthenticated, (authenticated, was) => {
  if (was && !authenticated) {
    void webPush.forget();
  }
});
void auth.init().then(() => (auth.isAuthenticated ? webPush.refreshIfRegistered() : undefined));
app.use(router);
app.mount('#app');
