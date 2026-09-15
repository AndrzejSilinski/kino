<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;

/**
 * Operacja płatnicza na rezerwacji, która nie jest już w stanie pending:
 * wygasła, została anulowana albo jest już opłacona.
 *
 * 409, bo to konflikt ze stanem zasobu, a nie błąd składni żądania.
 * Status wędruje w context(), żeby frontend mógł pokazać właściwy ekran:
 * inny komunikat po wygaśnięciu, inny przy już opłaconej rezerwacji.
 */
class BookingNotPayableException extends CinemaException
{
    public function __construct(public readonly BookingStatus $status)
    {
        parent::__construct(match ($status) {
            BookingStatus::Expired => 'Czas na opłacenie tej rezerwacji minął.',
            BookingStatus::Paid => 'Ta rezerwacja została już opłacona.',
            default => 'Tej rezerwacji nie można już opłacić.',
        });
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'BOOKING_NOT_PAYABLE';
    }

    public function context(): array
    {
        return ['booking_status' => $this->status->value];
    }
}
