<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Co najmniej jedno z wybranych miejsc jest w tej chwili zajęte —
 * trzyma je aktywna blokada innej sesji albo zostało już sprzedane.
 *
 * TO JEST TEN BŁĄD 409 Z ZADANIA. Przy N równoczesnych żądaniach na to samo
 * miejsce dokładnie jedno kończy się sukcesem, a pozostałe N-1 dostaje właśnie
 * ten wyjątek — czytelny, z listą miejsc, bez śladu SQL-a.
 *
 * DLACZEGO NIESIEMY LISTĘ MIEJSC:
 * frontend musi wiedzieć, KTÓRE fotele odświeżyć i podświetlić jako zajęte.
 * Samo "409 Conflict" zmusiłoby go do przeładowania całego planu sali.
 * $seatLabels ("B7", "C12") jest dla komunikatu widocznego dla klienta —
 * użytkownik nie zna wewnętrznych identyfikatorów miejsc.
 */
class SeatsUnavailableException extends CinemaException
{
    /**
     * @param  list<int>     $seatIds
     * @param  list<string>  $seatLabels
     */
    public function __construct(
        public readonly array $seatIds,
        public readonly array $seatLabels = [],
    ) {
        $message = $seatLabels === []
            ? 'Wybrane miejsca zostały właśnie zajęte przez kogoś innego.'
            : 'Miejsca '.implode(', ', $seatLabels).' zostały właśnie zajęte przez kogoś innego.';

        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'SEATS_UNAVAILABLE';
    }

    public function context(): array
    {
        return [
            'seat_ids' => $this->seatIds,
            'seats' => $this->seatLabels,
        ];
    }
}
