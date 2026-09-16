<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown artykułu -> bezpieczny HTML (Etap 7, blok M).
 *
 * Jedno miejsce konfiguracji dla API i podglądu w panelu:
 *   html_input = strip        — surowy HTML z treści (<script>, <iframe>, onclick)
 *                               znika; zostaje tylko to, co generuje sam Markdown,
 *   allow_unsafe_links = false — linki javascript:, vbscript:, file: i data: tracą href,
 *   max_nesting_level = 20    — głęboko zagnieżdżone listy nie zajmą CPU na długo.
 *
 * Vue i Flutter wstawiają body_html do widoku jako HTML, więc to jest granica
 * bezpieczeństwa klientów — stąd testy jednostkowe na złośliwe wejście.
 */
final class ArticleMarkdown
{
    public static function toHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }
}
