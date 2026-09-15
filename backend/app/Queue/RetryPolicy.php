<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Polityka ponawiania zadań z kolejki — jedna dla całej aplikacji.
 *
 * Wymaganie z zadania: maksymalnie 3 próby z wykładniczym opóźnieniem.
 * "3 próby" liczymy łącznie z pierwszą, więc ponowienia są dwa:
 *
 *   próba 1 — od razu
 *   próba 2 — po ~10 s  (BASE_DELAY_SECONDS)
 *   próba 3 — po ~40 s  (BASE_DELAY_SECONDS * MULTIPLIER)
 *
 * Dlaczego opóźnienie rośnie wykładniczo: typowa przyczyna awarii zadania
 * to chwilowa niedostępność zależności (SMTP, Stripe, FCM). Ponowienie
 * sekundę później trafia w tę samą awarię i tylko ją pogłębia.
 *
 * Dlaczego losowy rozrzut (jitter, do 20%): przy premierze setki maili
 * zawodzą w tej samej sekundzie, gdy serwer poczty się zawiesi. Bez
 * rozrzutu wszystkie ponowiłyby się też w tej samej sekundzie i położyły
 * go znowu (efekt "thundering herd").
 *
 * Stałe w kodzie, nie w .env: to kontrakt z treści zadania, a nie parametr
 * strojony per środowisko. Zmiana ma przejść przez przegląd kodu i test.
 */
final class RetryPolicy
{
    /** Łączna liczba prób, łącznie z pierwszą. */
    public const MAX_ATTEMPTS = 3;

    /** Opóźnienie przed pierwszym ponowieniem, w sekundach. */
    public const BASE_DELAY_SECONDS = 10;

    /** Każde kolejne opóźnienie jest tyle razy dłuższe od poprzedniego. */
    public const MULTIPLIER = 4;

    /** Maksymalny losowy dodatek, w procentach opóźnienia bazowego. */
    public const JITTER_PERCENT = 20;

    /**
     * Opóźnienia bez losowości: [10, 40]. Wzorzec dla testów i dokumentacji.
     *
     * @return list<int>
     */
    public static function baseDelays(): array
    {
        $delays = [];

        for ($retry = 0; $retry < self::MAX_ATTEMPTS - 1; $retry++) {
            $delays[] = self::BASE_DELAY_SECONDS * self::MULTIPLIER ** $retry;
        }

        return $delays;
    }

    /**
     * Opóźnienia z losowym dodatkiem, np. [11, 46].
     *
     * Laravel woła tę metodę RAZ, przy wstawianiu zadania do kolejki,
     * i zapisuje wynik w jego treści (pole "backoff"). Każde zadanie ma
     * więc własny, stały rozrzut na wszystkie swoje ponowienia.
     *
     * @return list<int>
     */
    public static function delays(): array
    {
        return array_map(
            static fn (int $delay): int => $delay + random_int(0, intdiv($delay * self::JITTER_PERCENT, 100)),
            self::baseDelays(),
        );
    }
}
