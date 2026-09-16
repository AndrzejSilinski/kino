<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Wynik rozliczenia płatności anulowanej rezerwacji (Etap 7, blok K).
 *
 * Należy do naszej domeny, jak PaymentIntentStatus: panel pokazuje komunikat,
 * komenda liczy wyniki, a żadne z nich nie musi znać statusów Stripe'a.
 */
enum RefundOutcome: string
{
    /** Rezerwacja nie miała płatności u operatora — nie ma czego rozliczać. */
    case NotRequired = 'not_required';

    /** Autoryzacja zwolniona albo płatność anulowana: pieniądze nie zostały pobrane. */
    case Voided = 'voided';

    /** Pobrane pieniądze zwrócone klientowi. */
    case Refunded = 'refunded';

    /** Operator niedostępny albo płatność w toku — ponowi komenda z harmonogramu. */
    case Pending = 'pending';

    /** Komunikat dla administratora po anulowaniu w panelu. */
    public function message(): string
    {
        return match ($this) {
            self::NotRequired => 'Rezerwacja anulowana. Nie było płatności do rozliczenia.',
            self::Voided => 'Rezerwacja anulowana. Płatność u operatora anulowana przed pobraniem — klient nic nie zapłacił.',
            self::Refunded => 'Rezerwacja anulowana. Zwrot pieniędzy został zlecony u operatora płatności.',
            self::Pending => 'Rezerwacja anulowana, miejsca wróciły do sprzedaży. Rozliczenie płatności jeszcze się nie udało — system ponowi je automatycznie.',
        };
    }
}
