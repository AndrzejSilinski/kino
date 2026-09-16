<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status artykułu (Etap 7, blok M). "Zaplanowany" nie jest osobnym stanem:
 * to Published z datą publikacji w przyszłości — patrz Article::isVisibleAt().
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Published => 'Opublikowany',
        };
    }
}
