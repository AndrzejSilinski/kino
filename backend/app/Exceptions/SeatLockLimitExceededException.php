<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Sesja próbuje trzymać więcej miejsc, niż pozwala konfiguracja
 * (config('cinema.seat_lock.max_seats_per_session'), domyślnie 10).
 *
 * PO CO TEN LIMIT:
 * to zabezpieczenie przed prostym atakiem DoS na sprzedaż — bez niego jedna
 * sesja mogłaby zablokować całą salę na 10 minut i wyprzedaż stałaby w miejscu.
 * Limit liczymy dla CAŁEJ sesji na danym seansie, a nie dla pojedynczego
 * żądania — inaczej wystarczyłoby wysłać dziesięć żądań po jednym miejscu.
 */
class SeatLockLimitExceededException extends CinemaException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $requested,
    ) {
        parent::__construct("W jednym zamówieniu można zarezerwować maksymalnie {$limit} miejsc.");
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'SEAT_LOCK_LIMIT_EXCEEDED';
    }

    public function context(): array
    {
        return ['limit' => $this->limit, 'requested' => $this->requested];
    }
}
