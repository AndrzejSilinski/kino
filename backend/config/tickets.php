<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bilety
|--------------------------------------------------------------------------
|
| Kod QR, PDF z biletami, walidacja przy wejściu na salę i przypomnienia
| o seansie. Przy każdej sekcji jest adnotacja, który blok Etapu 5 z niej
| korzysta. Kod aplikacji czyta wyłącznie config(), nigdy env().
|
*/

return [

    'qr' => [

        // Klucz HMAC podpisujący zawartość kodu QR (format T1.{uuid}.{mac}).
        // CELOWO bez wartości domyślnej: brak klucza ma zatrzymać aplikację
        // przy pierwszym użyciu, a nie podpisywać bilety pustym kluczem
        // (pułapka M). Osobny od APP_KEY, bo rotacja APP_KEY nie może
        // unieważnić sprzedanych biletów.
        'key' => env('TICKET_QR_KEY'),

        // Bok obrazu PNG w pikselach (Blok B). Wydruk 4 cm przy 300 dpi
        // to ok. 470 px, więc 480 px wystarcza bez rozmycia.
        'size' => 480,

        // Logo nakładane na środek kodu (Blok B). Plik leży w resources/,
        // a nie w public/: to zasób do generowania po stronie serwera,
        // a nie plik serwowany przeglądarce.
        'logo_path' => resource_path('images/cinema-logo.png'),

    ],

    'pdf' => [

        // Gdzie job zapisuje PDF z biletami (Bloki C i E). Dysk 'local'
        // wskazuje storage/app/private, czyli miejsce spoza public/.
        'disk' => 'local',
        'directory' => 'tickets',

    ],

    'validation' => [

        // Od ilu minut przed rozpoczęciem seansu obsługa może skanować
        // bilety (Blok F). Wcześniejszy skan kończy się błędem
        // TICKET_OUTSIDE_VALIDATION_WINDOW.
        'opens_minutes_before' => (int) env('TICKET_VALIDATION_OPENS_MINUTES', 60),

    ],

    'confirmations' => [

        // Po ilu minutach od płatności bez wysłanego maila komenda
        // cinema:bookings:resend-confirmations uznaje potwierdzenie za zgubione
        // (Blok G). 15 minut to z zapasem czas normalnej ścieżki razem
        // z ponowieniami (10 s + 40 s) i kolejką w godzinach szczytu.
        'retry_after_minutes' => (int) env('CONFIRMATION_RETRY_AFTER_MINUTES', 15),

        // Jak długo ponawiamy automatycznie. Starsze przypadki wymagają
        // ręcznej decyzji (opcja --all), żeby trwała awaria nie zasypała
        // failed_jobs co kwadrans przez całą dobę.
        'retry_window_minutes' => (int) env('CONFIRMATION_RETRY_WINDOW_MINUTES', 120),

    ],

    'reminders' => [

        // Ile minut przed seansem wysyłamy przypomnienie (Blok G).
        'minutes_before' => (int) env('SCREENING_REMINDER_MINUTES', 120),

    ],

];
