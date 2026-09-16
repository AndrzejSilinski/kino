/*
 * Echo w panelu (Etap 7, blok L). Ładowany w <head> PRZED skryptem Livewire:
 * Livewire zakłada nasłuchy "echo-private:..." przy starcie komponentu i szuka
 * wtedy window.Echo.
 *
 * - echo.iife.js tworzy globalne Echo jako PRZESTRZEŃ NAZW; klasa jest pod .default.
 *   Tu zastępujemy je instancją, której oczekuje Livewire.
 * - Adres WebSocketu = adres strony: nginx przekazuje /app/ do Reverba (Etap 6),
 *   więc przeglądarka nie zna portu Reverba.
 * - Podpisy kanałów: POST /admin/broadcasting/auth z ciasteczkiem sesji. Token CSRF
 *   Echo czyta sam z <meta name="csrf-token">.
 * - Po odzyskaniu połączenia wysyłamy do Livewire zdarzenie realtime-reconnected:
 *   zdarzenia z czasu przerwy przepadły, pulpit odtwarza feed z bazy.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="reverb-key"]');

    if (!meta || !window.Pusher || !window.Echo || typeof window.Echo.default !== 'function') {
        return;
    }

    var secure = window.location.protocol === 'https:';
    var port = Number(window.location.port) || (secure ? 443 : 80);
    var labels = {
        initialized: 'łączenie…',
        connecting: 'łączenie…',
        connected: 'połączono',
        unavailable: 'brak połączenia (odświeżanie co minutę)',
        failed: 'niedostępne (odświeżanie co minutę)',
        disconnected: 'rozłączono'
    };

    window.Echo = new window.Echo.default({
        broadcaster: 'reverb',
        key: meta.getAttribute('content'),
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        // Tylko transport zgodny ze stroną: na http pusher-js próbowałby też wss i sypał błędami TLS.
        enabledTransports: [secure ? 'wss' : 'ws'],
        authEndpoint: meta.getAttribute('data-auth-endpoint')
    });

    var wasConnected = false;
    var lost = false;

    window.Echo.connector.pusher.connection.bind('state_change', function (states) {
        document.querySelectorAll('[data-realtime-status]').forEach(function (el) {
            el.textContent = labels[states.current] || states.current;
            el.setAttribute('data-state', states.current);
        });

        if (states.current === 'connected') {
            if (lost && window.Livewire) {
                window.Livewire.dispatch('realtime-reconnected');
            }
            wasConnected = true;
            lost = false;
        } else if (wasConnected) {
            lost = true;
        }
    });
})();
