<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Odmowa subskrypcji prywatnego kanału WebSocket (Etap 6).
 *
 * Jeden komunikat i jeden kod dla każdej przyczyny: kanał nie istnieje,
 * zasób nie istnieje, brak uprawnień. Rozróżnienie zdradzałoby, czy
 * rezerwacja o danym ULID-zie istnieje — ta sama zasada co przy
 * trasach /bookings/{booking} (403, a nie 404 dla cudzej rezerwacji).
 */
final class ChannelAccessDeniedException extends CinemaException
{
    public function __construct()
    {
        parent::__construct('Brak dostępu do tego kanału.');
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'CHANNEL_FORBIDDEN';
    }
}
