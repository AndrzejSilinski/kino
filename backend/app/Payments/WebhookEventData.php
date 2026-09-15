<?php

declare(strict_types=1);

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * Zweryfikowane zdarzenie webhooka, sprowadzone do tego, czego używamy.
 *
 * createdAt to czas powstania zdarzenia PO STRONIE DOSTAWCY, nie czas
 * odebrania. Różnica między nimi to opóźnienie dostarczenia i właśnie ona
 * decyduje o tym, czy blokada miejsc zdążyła wygasnąć.
 *
 * intent jest nullem dla zdarzeń, które nie dotyczą płatności — takie
 * po prostu odnotowujemy i ignorujemy.
 */
final readonly class WebhookEventData
{
    public function __construct(
        public string $id,
        public string $type,
        public CarbonImmutable $createdAt,
        public ?PaymentIntentData $intent = null,
    ) {}
}
