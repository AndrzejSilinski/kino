<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Bezpiecznik wysyłki zdarzeń WebSocket (Etap 6, blok H).
 *
 * PROBLEM: przy niedziałającym Reverbie każda próba wysyłki kosztuje
 * connect_timeout (0,5 s). Przejście rezerwacji wysyła do trzech zdarzeń,
 * więc przebieg wygaszania 100 rezerwacji trwałby ~150 s zamiast ułamka sekundy.
 *
 * ROZWIĄZANIE: po porażce zapisujemy w cache klucz z czasem życia (domyślnie
 * 10 s). Dopóki klucz istnieje, notifier nie próbuje wysyłać. Gdy wygaśnie,
 * następna wysyłka znów próbuje, a kolejna porażka otwiera bezpiecznik ponownie.
 *
 * CACHE, NIE POLE STATYCZNE: php-fpm, worker i scheduler to osobne procesy,
 * a PHP zeruje stan po każdym żądaniu. Klucz w Redisie widzą wszystkie.
 *
 * add() ZAMIAST put(): Redis zapisuje klucz tylko wtedy, gdy go nie ma
 * (SET NX), więc z kilku procesów, które zawiodły jednocześnie, dokładnie
 * jeden dostaje true i zapisuje ostrzeżenie w logu.
 *
 * AWARIA CACHE NIE BLOKUJE WYSYŁKI (fail-open): gdy Redis nie odpowiada,
 * bezpiecznik zachowuje się jak zamknięty, czyli jak notifier sprzed bloku H.
 */
final class RealtimeCircuitBreaker
{
    private const KEY = 'realtime:breaker-open';

    public function __construct(
        private readonly Cache $cache,
    ) {}

    /** Czy wysyłka jest wstrzymana. */
    public function isOpen(): bool
    {
        if ($this->seconds() === 0) {
            return false;
        }

        try {
            return $this->cache->has(self::KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Otwiera bezpiecznik po nieudanej wysyłce.
     *
     * Zwraca true, gdy to wywołanie go otworzyło (albo bezpiecznik jest
     * wyłączony lub cache nie działa) — wtedy notifier loguje ostrzeżenie.
     */
    public function trip(): bool
    {
        $seconds = $this->seconds();

        if ($seconds === 0) {
            return true;
        }

        try {
            return $this->cache->add(self::KEY, now()->toIso8601String(), $seconds);
        } catch (Throwable) {
            return true;
        }
    }

    /** Czas wstrzymania w sekundach; 0 wyłącza bezpiecznik. */
    public function seconds(): int
    {
        return max(0, (int) config('broadcasting.breaker_seconds', 10));
    }
}
