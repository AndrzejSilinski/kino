<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Żądanie na endpoint webhooka miało zły podpis, zły znacznik czasu
 * albo nie było poprawnym JSON-em.
 *
 * 400, bo żądanie jest wadliwe. Celowo NIE 401 ani 403: te sugerowałyby,
 * że istnieje jakieś logowanie, którego można spróbować ponownie.
 *
 * Komunikat jest maksymalnie ubogi. Każdy szczegół ("zły znacznik czasu",
 * "brak nagłówka") podpowiadałby atakującemu, jak poprawić podróbkę.
 * Szczegóły idą do logu po naszej stronie, nie do odpowiedzi.
 */
class InvalidWebhookSignatureException extends CinemaException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Nieprawidłowe żądanie.', 0, $previous);
    }

    public function status(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'INVALID_WEBHOOK_SIGNATURE';
    }
}
