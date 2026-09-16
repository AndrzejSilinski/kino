<?php

declare(strict_types=1);

/*
 * Konfiguracja domenowa kina.
 *
 * Wszystko, co recruiter lub devops mógłby chcieć zmienić bez dotykania kodu,
 * siedzi tutaj i jest sterowane zmienną środowiskową. Kod NIGDY nie czyta env()
 * bezpośrednio — tylko config(), bo po `php artisan config:cache` wywołania env()
 * poza plikami konfiguracyjnymi zwracają null. To jeden z klasycznych błędów
 * wdrożeniowych w Laravelu.
 */

return [

    'seat_lock' => [

        // Czas życia blokady miejsca w sekundach. Zadanie sugeruje 10 minut:
        // tyle, żeby zdążyć zapłacić, i nie więcej, żeby porzucone koszyki
        // nie blokowały sali podczas premiery.
        'ttl' => (int) env('SEAT_LOCK_TTL', 600),

        // Maksymalna liczba miejsc trzymanych jednocześnie przez jedną sesję
        // na jednym seansie. Zabezpieczenie przed zablokowaniem całej sali
        // przez jednego klienta (prosty DoS na sprzedaż).
        'max_seats_per_session' => (int) env('SEAT_LOCK_MAX_SEATS', 10),

        // Ile wygasłych blokad scheduler zwalnia w jednym przebiegu.
        // Porcjowanie pilnuje, żeby czyszczenie nie zakładało locków na
        // dziesiątkach tysięcy wierszy i nie wstrzymywało sprzedaży.
        'sweep_batch' => (int) env('SEAT_LOCK_SWEEP_BATCH', 500),

    ],

    'screening' => [

        // Blok reklam przed filmem — wydłuża realny czas zajęcia sali.
        'ads_minutes' => (int) env('SCREENING_ADS_MINUTES', 15),

        // Bufor na sprzątanie sali po seansie. Razem z ads_minutes wyznacza
        // slot_ends_at, którego pilnuje constraint EXCLUDE na tabeli screenings —
        // to on blokuje kolizje repertuaru w tej samej sali.
        'cleanup_buffer_minutes' => (int) env('SCREENING_CLEANUP_BUFFER_MINUTES', 20),

    ],

    // Dokąd trafia wyjście komend uruchamianych przez scheduler (pułapka V).
    // Domyślnie /dev/null, jak w samym Laravelu. Kontener scheduler ustawia
    // SCHEDULE_OUTPUT=/proc/1/fd/2, czyli stderr swojego procesu głównego,
    // który czyta docker logs. Wartość z docker-compose ma pierwszeństwo przed
    // .env, bo Dotenv nie nadpisuje zmiennych, które już są w środowisku.
    'schedule_output' => env('SCHEDULE_OUTPUT', '/dev/null'),

    // Cache katalogu w Redisie (Etap 7, blok C): repertuar, dni z seansami, kina.
    // Główna inwalidacja to liczniki generacji podbijane po COMMIT (CatalogCache);
    // TTL jest zabezpieczeniem i sprzątaniem starych kluczy.
    'catalog_cache' => [

        'ttl_seconds' => (int) env('CATALOG_CACHE_TTL', 600),

        // Zamek przeciw stampede: jak długo trzymamy go przy liczeniu wartości
        // i ile sekund inni czytelnicy czekają na gotowy wynik.
        'lock_seconds' => 10,
        'lock_wait_seconds' => 2,

    ],

    'booking' => [

        // Waluta rozliczeniowa. Kwoty trzymamy jako integer w groszach —
        // nigdy float. Stripe operuje na tych samych jednostkach minorowych.
        'currency' => env('BOOKING_CURRENCY', 'PLN'),

    ],

];
