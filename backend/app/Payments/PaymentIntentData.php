<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Płatność u dostawcy, w naszych polach.
 *
 * readonly, bo to zdjęcie stanu z konkretnej chwili — zmiana pola nie
 * zmieniłaby niczego u dostawcy, więc lepiej, żeby była niemożliwa.
 *
 * Kwoty w najmniejszej jednostce (grosze), zgodnie z decyzją #4.
 * Stripe używa tych samych jednostek, więc nic nie przeliczamy.
 */
final readonly class PaymentIntentData
{
    public function __construct(
        public string $id,
        public PaymentIntentStatus $status,
        public int $amountMinor,
        public int $amountCapturableMinor,
        public int $amountReceivedMinor,
        public string $currency,
        /** Tajemnica dla klienta: Payment Element i PaymentSheet biorą właśnie ją. */
        public ?string $clientSecret = null,
        /** Nasza referencja rezerwacji przeniesiona w metadanych płatności. */
        public ?string $bookingReference = null,
        /** Kod ostatniego błędu płatności, np. card_declined. */
        public ?string $lastErrorCode = null,
    ) {}
}
