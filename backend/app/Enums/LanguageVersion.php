<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageVersion: string
{
    case Original = 'original';
    case Subtitles = 'subtitles';
    case Dubbing = 'dubbing';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Wersja oryginalna',
            self::Subtitles => 'Napisy',
            self::Dubbing => 'Dubbing',
        };
    }
}
