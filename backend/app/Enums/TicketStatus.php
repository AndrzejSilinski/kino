<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';         // wystawiony, jeszcze niezeskanowany
    case Used = 'used';           // zeskanowany przy wejściu na salę
    case Cancelled = 'cancelled'; // rezerwacja anulowana lub zwrócona

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Ważny',
            self::Used => 'Wykorzystany',
            self::Cancelled => 'Anulowany',
        };
    }
}
