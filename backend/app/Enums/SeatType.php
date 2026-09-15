<?php

declare(strict_types=1);

namespace App\Enums;

enum SeatType: string
{
    case Standard = 'standard';
    case Double = 'double';       // love seat: jedno miejsce, jeden bilet, cena pakietowa
    case Accessible = 'accessible';

    /**
     * Etykieta pokazywana klientowi.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Miejsce standardowe',
            self::Double => 'Miejsce podwójne',
            self::Accessible => 'Miejsce dla osób z niepełnosprawnością',
        };
    }
}
