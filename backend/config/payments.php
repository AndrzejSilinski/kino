<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Płatności
|--------------------------------------------------------------------------
|
| Ustawienia biznesowe płatności (okno płatności) i dane dostępowe
| do Stripe'a. Trzymamy je razem, bo czyta je wyłącznie warstwa płatności
| (app/Payments, PaymentService), a config/cinema.php zostaje dla sal,
| seansów i blokad miejsc.
|
*/

return [

    /*
     * Ile sekund klient ma na dokończenie płatności od chwili utworzenia
     * rezerwacji. Blokady miejsc tej rezerwacji dostają ten sam termin.
     *
     * To wygoda użytkownika, a nie gwarancja poprawności: o tym, czy miejsce
     * nadal należy do rezerwacji, decyduje wiersz w seat_locks (released_at
     * IS NULL), a nie porównanie z zegarem.
     */
    'window_seconds' => (int) env('PAYMENT_WINDOW_SECONDS', 600),

    'stripe' => [

        // Klucz publiczny (pk_test_...). Wysyłamy go klientom Vue i Flutter.
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),

        // Klucz tajny (sk_test_...). Nigdy nie opuszcza serwera.
        'secret_key' => env('STRIPE_SECRET_KEY'),

        // Sekret podpisu webhooka (whsec_...). Stripe CLI i endpoint
        // skonfigurowany w Dashboardzie mają RÓŻNE sekrety.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Maksymalny wiek zdarzenia w sekundach — ochrona przed powtórzeniem
        // przechwyconego, poprawnie podpisanego zdarzenia (replay).
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),

        // Automatyczne ponowienia SDK przy błędach sieci.
        'max_network_retries' => (int) env('STRIPE_MAX_NETWORK_RETRIES', 2),

        // Limity czasu HTTP w sekundach. Domyślne wartości SDK (80 s na całe
        // żądanie, 30 s na połączenie) są za długie dla żądania, na które
        // czeka klient przy kasie albo Stripe przy webhooku.
        'timeout_seconds' => (int) env('STRIPE_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => (int) env('STRIPE_CONNECT_TIMEOUT_SECONDS', 5),

    ],

];
