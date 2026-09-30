<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Zwrot u dostawcy, w naszych polach (Etap 10, blok C).
 *
 * Do tej pory webhook znał tylko płatności (PaymentIntentData). Zwrot potrafi się nie udać
 * PO tym, jak operator go przyjął — karta zamknięta, zgubiona, spór o płatność — i wtedy
 * operator wysyła osobne zdarzenie o obiekcie zwrotu, a nie płatności.
 *
 * failureReason to kod dostawcy (np. expired_or_canceled_card), zapisywany w rezerwacji
 * dosłownie; polski opis dla panelu daje Labels::refundFailureReason().
 */
final readonly class RefundData
{
    public function __construct(
        public string $id,
        /** Płatność, której dotyczy zwrot — po niej znajdujemy rezerwację. */
        public ?string $paymentIntentId,
        public bool $failed,
        public ?string $failureReason = null,
    ) {}
}
