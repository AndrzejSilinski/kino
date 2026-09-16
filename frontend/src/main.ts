/*
 * Punkt wejścia SPA (Etap 8).
 *
 * Kolejność: Pinia -> klient HTTP z dostępem do tokenu -> strażnicy tras -> router -> montaż.
 * Strażnik czeka na auth.init() (odczyt /auth/me przy zapisanym tokenie), więc pierwszy ekran
 * nie mignie jako "gość" u zalogowanego użytkownika.
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { configureHttp } from './api/client';
import { router } from './router';
import { installAuthGuards } from './router/guards';
import { useAuthStore } from './stores/auth';
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
app.use(router);
app.mount('#app');
