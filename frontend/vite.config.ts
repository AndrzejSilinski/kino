/*
 * Etap 8, blok C: konfiguracja Vite i Vitest dla SPA klienta.
 *
 * Tryb deweloperski (serwis "frontend" w docker-compose, profil dev): Vite w kontenerze
 * Node na porcie 5173. Przeglądarka rozmawia WYŁĄCZNIE z Vite, a Vite przekazuje API,
 * pliki z /storage i WebSocket (/app/) do nginx w sieci Dockera. Dla przeglądarki to
 * jeden origin (localhost:5173), więc nie potrzebujemy CORS ani Access-Control-Expose-Headers.
 *
 * Tryb "jak produkcja": npm run build -> dist/, który nginx serwuje pod localhost:8080
 * obok Laravela (ten sam origin co API).
 */
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

// Konfiguracja wykonuje się w Node; deklaracja zamiast całego pakietu @types/node dla jednej zmiennej.
declare const process: { env: Record<string, string | undefined> };

// Adres nginx widziany z kontenera frontend. Poza Dockerem: VITE_PROXY_TARGET=http://localhost:8080.
const proxyTarget = process.env.VITE_PROXY_TARGET ?? 'http://nginx';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
    },
  },
  server: {
    proxy: {
      '/api/': { target: proxyTarget },
      '/storage/': { target: proxyTarget },
      // Reverb za nginx (Etap 6): upgrade HTTP -> WebSocket musi przejść także przez proxy Vite.
      '/app/': { target: proxyTarget, ws: true },
    },
  },
  build: {
    // Mapy źródeł nie trafiają do obrazu serwowanego klientom.
    sourcemap: false,
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
    restoreMocks: true,
  },
});
