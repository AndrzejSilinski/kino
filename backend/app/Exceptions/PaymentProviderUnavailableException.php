<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Nie udało się porozmawiać z dostawcą płatności: sieć, timeout albo
 * chwilowy limit żądań.
 *
 * 503, a nie 402. To nie jest problem z kartą klienta ani jego błąd —
 * to nasza infrastruktura. Status 503 mówi klientowi (i monitoringowi),
 * że żądanie warto ponowić, a 402 sugerowałoby, żeby zmienił kartę.
 */
class PaymentProviderUnavailableException extends CinemaException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Operator płatności jest chwilowo niedostępny. Spróbuj ponownie za moment.',
            0,
            $previous,
        );
    }

    public function status(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'PAYMENT_PROVIDER_UNAVAILABLE';
    }
}
