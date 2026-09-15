<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Dostawca odrzucił naszą operację na płatności.
 *
 * 402 Payment Required — status wskazany w zadaniu dla problemów
 * z płatnością. Kod błędu dostawcy trafia do context(), a nie do
 * komunikatu: komunikat jest dla człowieka, kod dla frontendu i dla nas
 * przy analizie zgłoszenia.
 *
 * Świadomie NIE przekazujemy klientowi oryginalnej treści błędu Stripe'a.
 * Bywa po angielsku, bywa techniczna, a czasem zawiera szczegóły
 * konfiguracji konta, których nie chcemy pokazywać na zewnątrz.
 */
class PaymentRejectedException extends CinemaException
{
    public function __construct(
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Płatności nie udało się zrealizować.', 0, $previous);
    }

    public function status(): int
    {
        return 402;
    }

    public function errorCode(): string
    {
        return 'PAYMENT_REJECTED';
    }

    public function context(): array
    {
        return ['provider_code' => $this->providerCode];
    }
}
