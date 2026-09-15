<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Screening;

/**
 * Seans nie przyjmuje już rezerwacji: został odwołany, oznaczony jako
 * zakończony albo zdążył się rozpocząć.
 *
 * DLACZEGO 409, A NIE 422:
 * Żądanie jest poprawnie zbudowane — identyfikatory istnieją, format się zgadza.
 * To STAN zasobu na serwerze nie pozwala go wykonać, a dokładnie taka jest
 * semantyka 409 Conflict. Kod 422 rezerwujemy na błędy w danych wejściowych.
 */
class ScreeningNotBookableException extends CinemaException
{
    public function __construct(public readonly int $screeningId)
    {
        parent::__construct('Na ten seans nie można już rezerwować miejsc.');
    }

    public static function for(Screening $screening): self
    {
        return new self($screening->id);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'SCREENING_NOT_BOOKABLE';
    }

    public function context(): array
    {
        return ['screening_id' => $this->screeningId];
    }
}
