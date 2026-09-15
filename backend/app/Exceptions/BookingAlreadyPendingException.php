<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Sesja ma już rozpoczętą płatność, a koszyk zmienił się od tamtej chwili
 * (doszło nowe miejsce albo blokady należą do różnych rezerwacji).
 *
 * Nie tworzymy po cichu drugiej rezerwacji: klient miałby dwie płatności
 * na te same fotele. Nie zwracamy też po cichu starej, bo zapłaciłby za
 * inny zestaw miejsc niż ten, który widzi na ekranie.
 *
 * Referencja idzie w context(), żeby frontend mógł zaproponować konkretny
 * wybór: dokończ tamtą płatność albo ją anuluj.
 */
class BookingAlreadyPendingException extends CinemaException
{
    public function __construct(public readonly ?string $reference = null)
    {
        parent::__construct('Masz już rozpoczętą płatność za te miejsca. Dokończ ją albo anuluj.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'BOOKING_ALREADY_PENDING';
    }

    public function context(): array
    {
        return ['booking_reference' => $this->reference];
    }
}
