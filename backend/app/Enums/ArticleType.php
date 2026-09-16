<?php

declare(strict_types=1);

namespace App\Enums;

/** Rodzaj artykułu modułu informacyjnego (Etap 7, blok M). */
enum ArticleType: string
{
    case News = 'news';
    case Premiere = 'premiere';

    public function label(): string
    {
        return match ($this) {
            self::News => 'Aktualności',
            self::Premiere => 'Nadchodzące premiery',
        };
    }
}
