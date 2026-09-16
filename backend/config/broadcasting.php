<?php

/*
|--------------------------------------------------------------------------
| Broadcasting — Etap 6
|--------------------------------------------------------------------------
| Laravel publikuje zdarzenie do Reverba ZWYKŁYM ŻĄDANIEM HTTP
| (POST /apps/{app_id}/events), podpisanym sekretem aplikacji. Wysyła je
| klient Pushera (pusher/pusher-php-server) zbudowany na Guzzle, bo Reverb
| mówi protokołem Pushera. Reverb rozsyła je dalej do subskrybentów kanału.
|
| Zostawiamy tylko połączenia, których używamy:
|   reverb — środowisko deweloperskie i produkcja,
|   log    — podgląd zdarzeń w logu bez uruchomionego serwera,
|   null   — testy (phpunit.xml) i procesy potomne testu współbieżności.
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
     * Górny limit rozmiaru payloadu zdarzenia (JSON, w bajtach). Reverb
     * przyjmuje żądanie publikacji do REVERB_MAX_REQUEST_SIZE (domyślnie
     * 10 000 bajtów), a payload jest w nim zakodowany jako napis JSON razem
     * z nazwą zdarzenia i kanałów. 8000 zostawia zapas; większa zmiana
     * idzie jako seats.resync (RealtimeNotifier).
     */
    'max_payload_bytes' => (int) env('BROADCAST_MAX_PAYLOAD_BYTES', 8000),

    /*
     * Bezpiecznik (blok H): po nieudanej wysyłce notifier przez tyle sekund
     * nie łączy się z Reverbem. Stan jest w cache aplikacji (Redis), więc
     * widzą go wszystkie procesy: php-fpm, worker i scheduler. 0 wyłącza.
     */
    'breaker_seconds' => (int) env('BROADCAST_BREAKER_SECONDS', 10),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),

            /*
             * Adres Reverba widziany Z KONTENERÓW PHP (php, worker, scheduler).
             * W sieci Dockera to http://reverb:8080. Przeglądarka i telefon
             * łączą się inną drogą: przez nginx na porcie aplikacji, ścieżką
             * /app/{REVERB_APP_KEY}. Te dwa adresy celowo są rozdzielone.
             */
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],

            /*
             * Opcje klienta Guzzle, scalane z domyślnymi w BroadcastManager::pusher().
             *
             * Domyślnie framework czeka connect_timeout 10 s i timeout 30 s.
             * Przy niedziałającym Reverbie żądanie blokady miejsca wisiałoby
             * wtedy do 10 sekund. Broadcast to POWIADOMIENIE, a nie warunek
             * sprzedaży (jak listener w decyzji 73): czekamy krótko, a wyjątek
             * łapie i loguje notifier zdarzeń (blok F).
             */
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_CLIENT_CONNECT_TIMEOUT', 0.5),
                'timeout' => (float) env('REVERB_CLIENT_TIMEOUT', 1.5),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
