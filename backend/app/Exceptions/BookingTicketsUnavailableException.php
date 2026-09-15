<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;

/**
 * Pobranie PDF-a albo kodu QR dla rezerwacji, która nie jest opłacona.
 *
 * 409, bo to konflikt ze stanem zasobu, a nie brak uprawnień: właściciel
 * ma prawo zobaczyć rezerwację (policy przepuściła), ale oczekująca,
 * wygasła albo anulowana rezerwacja nie ma ważnych biletów. Status
 * w kontekście pozwala frontendowi pokazać właściwy komunikat.
 */
final class BookingTicketsUnavailableException extends CinemaException
{
    public function __construct(public readonly BookingStatus $bookingStatus)
    {
        parent::__construct('Bilety są dostępne wyłącznie dla opłaconej rezerwacji.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'BOOKING_TICKETS_UNAVAILABLE';
    }

    public function context(): array
    {
        return ['booking_status' => $this->bookingStatus->value];
    }
}
