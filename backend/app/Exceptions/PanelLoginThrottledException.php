<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Zbyt wiele nieudanych prób logowania do panelu (Etap 7, blok B3).
 *
 * 429 jak limiter API, ale liczone są WYŁĄCZNIE nieudane próby
 * (PanelAuthService), a udane logowanie zeruje licznik konta.
 * Czas do odblokowania jest w komunikacie i w context().
 */
final class PanelLoginThrottledException extends CinemaException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za {$retryAfterSeconds} s.");
    }

    public function status(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'PANEL_LOGIN_THROTTLED';
    }

    public function context(): array
    {
        return ['retry_after_seconds' => $this->retryAfterSeconds];
    }
}
