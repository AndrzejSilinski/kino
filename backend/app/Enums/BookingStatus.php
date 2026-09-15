<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    /** Utworzona, miejsca zablokowane, czekamy aż klient zapłaci. */
    case Pending = 'pending';

    /** Stripe potwierdził płatność, bilety zostały wygenerowane. */
    case Paid = 'paid';

    /** Klient porzucił płatność albo płatność się nie powiodła; miejsca zwolnione. */
    case Cancelled = 'cancelled';

    /** Blokady wygasły zanim płatność została potwierdzona. */
    case Expired = 'expired';

    /** Anulowana przez admina po opłaceniu; pieniądze zwrócone. */
    case Refunded = 'refunded';

    /**
     * Statusy, przy których miejsca muszą pozostać poza pulą dostępnych.
     */
    public function holdsSeats(): bool
    {
        return in_array($this, [self::Pending, self::Paid], strict: true);
    }
}
