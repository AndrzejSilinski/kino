<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Support\ArticleMarkdown;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as PagePaginator;

/**
 * Publiczne artykuły z cache w Redisie (Etap 7, blok M; wymóg 2.4).
 *
 * LISTA: jeden klucz (generacja ARTICLES) z WSZYSTKIMI opublikowanymi artykułami
 * — także zaplanowanymi na przyszłość — bez treści. Filtr "published_at <= teraz",
 * typ i stronę liczymy w PHP przy każdym odczycie. Dzięki temu:
 *   - zaplanowany artykuł pojawia się sam o swojej godzinie, bez zadania
 *     w harmonogramie i bez TTL dopasowanego do najbliższej publikacji,
 *   - liczba kluczy nie rośnie od ?type, ?page i ?per_page (jak filmy w bloku F).
 * Koszt: lista w pamięci rośnie z liczbą artykułów. Przy tysiącach — klucze
 * per strona z TTL ograniczonym najbliższą publikacją (Etap 10).
 *
 * TREŚĆ: osobny klucz na artykuł, z gotowym body_html (Markdown liczony raz).
 * O tym, czy artykuł istnieje i jest widoczny, decyduje LISTA z cache: nieznany
 * slug, szkic i artykuł zaplanowany dostają 404 bez zapytania do bazy i bez
 * zakładania nowego klucza — losowe adresy nie pompują Redisa.
 */
final class ArticleCatalogService
{
    public const DEFAULT_PER_PAGE = 10;

    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /** @return LengthAwarePaginator<int, Article> */
    public function published(?ArticleType $type, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $rows = array_values(array_filter(
            $this->visibleRows(CarbonImmutable::now()),
            fn (array $row): bool => $type === null || $row['type'] === $type->value,
        ));

        $page = PagePaginator::resolveCurrentPage();

        return (new PagePaginator(
            Article::hydrate(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            count($rows),
            $perPage,
            $page,
            ['path' => PagePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ))->withQueryString();
    }

    /** Opublikowany i widoczny artykuł z body_html albo null. */
    public function findPublished(string $slug): ?Article
    {
        $row = null;

        foreach ($this->visibleRows(CarbonImmutable::now()) as $candidate) {
            if ($candidate['slug'] === $slug) {
                $row = $candidate;
                break;
            }
        }

        if ($row === null) {
            return null;
        }

        $body = $this->catalog->remember(
            'articles:body:'.$row['id'],
            [CatalogCache::ARTICLES],
            fn (): ?string => ($markdown = Article::query()->whereKey($row['id'])->value('body')) === null
                ? null
                : ArticleMarkdown::toHtml($markdown),
        );

        if ($body === null) {
            return null;
        }

        $article = Article::hydrate([$row])->first();
        $article->setAttribute('body_html', $body);

        return $article;
    }

    /** @return list<array<string, mixed>> */
    private function visibleRows(CarbonImmutable $now): array
    {
        return array_values(array_filter(
            $this->allPublishedRows(),
            fn (array $row): bool => CarbonImmutable::parse($row['published_at'])->lessThanOrEqualTo($now),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function allPublishedRows(): array
    {
        return $this->catalog->remember(
            'articles:published',
            [CatalogCache::ARTICLES],
            fn (): array => Article::query()
                ->leftJoin('movies', 'movies.id', '=', 'articles.movie_id')
                ->where('articles.status', ArticleStatus::Published)
                ->orderByDesc('articles.published_at')
                ->orderByDesc('articles.id')
                ->get([
                    'articles.id', 'articles.type', 'articles.title', 'articles.slug', 'articles.excerpt',
                    'articles.published_at', 'articles.movie_id', 'movies.slug as movie_slug', 'movies.title as movie_title',
                ])
                ->map(fn (Article $article): array => $article->getAttributes())
                ->all(),
        );
    }
}
