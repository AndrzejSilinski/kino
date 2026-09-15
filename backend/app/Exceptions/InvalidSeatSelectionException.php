<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Wejście jest błędne: pusta lista, duplikaty, miejsce z innej sali
 * albo miejsce wyłączone z użytku.
 *
 * DLACZEGO 422: to problem z DANYMI ŻĄDANIA, nie ze stanem serwera.
 *
 * DLACZEGO JEDNA KLASA, A NIE CZTERY:
 * wszystkie te przypadki są tym samym rodzajem błędu i tak samo się je obsługuje
 * po stronie klienta. Rozróżnienie niesie pole $reason, które ląduje w errorCode().
 * Osobne klasy dawałyby cztery pliki o identycznej treści.
 *
 * NAZWANE KONSTRUKTORY zamiast publicznego __construct: wywołanie w serwisie
 * czyta się jak zdanie (InvalidSeatSelectionException::seatsNotInHall([...])),
 * a niepoprawnej kombinacji argumentów nie da się złożyć.
 */
class InvalidSeatSelectionException extends CinemaException
{
    /** @param list<int> $seatIds */
    private function __construct(
        string $message,
        private readonly string $reason,
        public readonly array $seatIds = [],
    ) {
        parent::__construct($message);
    }

    public static function emptySelection(): self
    {
        return new self('Nie wybrano żadnego miejsca.', 'EMPTY_SEAT_SELECTION');
    }

    /** @param list<int> $seatIds */
    public static function duplicates(array $seatIds): self
    {
        return new self('To samo miejsce zostało wybrane więcej niż raz.', 'DUPLICATE_SEATS', $seatIds);
    }

    /** @param list<int> $seatIds */
    public static function seatsNotInHall(array $seatIds): self
    {
        return new self('Wybrane miejsca nie należą do sali tego seansu.', 'SEATS_NOT_IN_HALL', $seatIds);
    }

    /** @param list<int> $seatIds */
    public static function inactiveSeats(array $seatIds): self
    {
        return new self('Wybrane miejsca są wyłączone ze sprzedaży.', 'SEATS_INACTIVE', $seatIds);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return $this->reason;
    }

    public function context(): array
    {
        return ['seat_ids' => $this->seatIds];
    }
}
