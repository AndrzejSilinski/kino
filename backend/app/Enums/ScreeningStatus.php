<?php

declare(strict_types=1);

namespace App\Enums;

enum ScreeningStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Finished = 'finished';   // ustawiane przez scheduler po zakończeniu seansu

    public function isBookable(): bool
    {
        return $this === self::Scheduled;
    }
}
