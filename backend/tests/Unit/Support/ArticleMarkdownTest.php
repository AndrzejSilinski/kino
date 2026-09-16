<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ArticleMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Markdown artykułu -> HTML (Etap 7, blok M). body_html trafia do Vue i Fluttera
 * jako HTML, więc to jest granica bezpieczeństwa klientów.
 */
final class ArticleMarkdownTest extends TestCase
{
    public function test_markdown_formatting_survives(): void
    {
        $html = ArticleMarkdown::toHtml("## Nagłówek\n\n- **pogrubione**\n- [link](https://kino.example/a?b=1&c=2)");

        $this->assertStringContainsString('<h2>Nagłówek</h2>', $html);
        $this->assertStringContainsString('<strong>pogrubione</strong>', $html);
        $this->assertStringContainsString('<a href="https://kino.example/a?b=1&amp;c=2">link</a>', $html);
    }

    /** @return array<string, array{string, string}> */
    public static function hostileInput(): array
    {
        return [
            'blok script' => ['<script>alert(1)</script>', '<script'],
            'script w akapicie' => ['Tekst <script>alert(1)</script> dalej', '<script'],
            'obrazek z onerror' => ['<img src=x onerror=alert(1)>', 'onerror'],
            'iframe' => ['<iframe src="https://zly.example"></iframe>', '<iframe'],
            'link javascript:' => ['[kliknij](javascript:alert(1))', 'javascript:'],
            'link wielkimi literami' => ['[kliknij](JaVaScRiPt:alert(1))', 'avascript:'],
            'link data:' => ['[x](data:text/html;base64,PHNjcmlwdD4=)', 'data:text/html'],
            'obrazek javascript:' => ['![x](javascript:alert(1))', 'javascript:'],
            // Tekst linku zostaje widoczny, ale bez href — nie da się w niego kliknąć.
            'autolink javascript' => ['<javascript:alert(1)>', 'href='],
            'atrybut przez tytuł linku' => ['[x](https://ok.example "a\" onmouseover=\"alert(1)")', 'onmouseover="'],
        ];
    }

    #[DataProvider('hostileInput')]
    public function test_raw_html_and_unsafe_links_never_reach_output(string $markdown, string $forbidden): void
    {
        $this->assertStringNotContainsStringIgnoringCase($forbidden, ArticleMarkdown::toHtml($markdown));
    }
}
