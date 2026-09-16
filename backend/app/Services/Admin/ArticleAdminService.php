<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Exceptions\InvalidArticleException;
use App\Exceptions\InvalidScreeningException;
use App\Models\Article;
use App\Models\Movie;
use App\Models\User;
use App\Support\CatalogCache;
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Artykuły w panelu (Etap 7, blok M; wymóg 2.4): tworzenie, edycja, usuwanie.
 *
 * USUWANIE JEST TWARDE — w przeciwieństwie do kin, sal i filmów artykułu nie
 * wskazuje żadna sprzedaż (R21 z rozpoznania).
 *
 * DATA PUBLIKACJI: panel podaje ją w strefie sieci (TIMEZONE), w bazie jest UTC.
 * Godziny nieistniejące i podwójne przy zmianie czasu odrzucamy tą samą regułą
 * co godziny seansów (ScreeningTimeline::localStart). Opublikowany bez daty
 * dostaje "teraz". Data w przyszłości = artykuł zaplanowany: API pokaże go sam
 * o tej godzinie, bez zadania w harmonogramie (ArticleCatalogService).
 *
 * INWALIDACJA po COMMIT: generacja ARTICLES — lista i treści w publicznym API.
 * Każda zmiana ją podbija, także zmiana szkicu: to tanie, a zasada "zapis
 * artykułu = nowa generacja" nie ma wyjątków, o których trzeba pamiętać.
 */
final class ArticleAdminService
{
    /** Strefa, w której redakcja podaje godzinę publikacji (sieć działa w Polsce). */
    public const TIMEZONE = 'Europe/Warsaw';

    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * @param  array{type: ArticleType, title: string, excerpt: string, body: string, movie_id: ?int, status: ArticleStatus, published_at: ?CarbonImmutable}  $data
     *
     * @throws InvalidArticleException
     */
    public function create(array $data, User $author): Article
    {
        $this->assertValid($data);

        return DB::transaction(function () use ($data, $author): Article {
            // Szeregujemy tworzenie: dwa artykuły z tym samym tytułem dostałyby ten sam
            // wolny slug i drugi INSERT rozbiłby się o UNIQUE (jak filmy w bloku F).
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['articles:create']);

            $article = new Article;
            $article->fill($this->attributes($data));
            $article->slug = $this->uniqueSlug($data['title']);
            $article->status = $data['status'];
            $article->published_at = $this->publishedAt($data);
            $article->author_id = $author->id;
            $article->save();

            $this->catalog->bump(CatalogCache::ARTICLES);

            return $article;
        });
    }

    /**
     * Slug się nie zmienia — patrz migracja tabeli articles.
     *
     * @param  array{type: ArticleType, title: string, excerpt: string, body: string, movie_id: ?int, status: ArticleStatus, published_at: ?CarbonImmutable}  $data
     *
     * @throws InvalidArticleException
     */
    public function update(Article $article, array $data): Article
    {
        $this->assertValid($data);

        return DB::transaction(function () use ($article, $data): Article {
            $fresh = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $fresh->fill($this->attributes($data));
            $fresh->status = $data['status'];
            $fresh->published_at = $this->publishedAt($data, $fresh);
            $fresh->save();

            $this->catalog->bump(CatalogCache::ARTICLES);

            return $fresh;
        });
    }

    public function delete(Article $article): void
    {
        DB::transaction(function () use ($article): void {
            Article::query()->whereKey($article->id)->delete();

            $this->catalog->bump(CatalogCache::ARTICLES);
        });
    }

    /**
     * Data i godzina z formularza (strefa TIMEZONE) -> UTC. Puste pola = null.
     *
     * @throws InvalidArticleException
     */
    public function localPublishTime(string $date, string $time): ?CarbonImmutable
    {
        if ($date === '' && $time === '') {
            return null;
        }

        try {
            return ScreeningTimeline::fromConfig()->localStart($date, $time, self::TIMEZONE);
        } catch (InvalidScreeningException $e) {
            throw InvalidArticleException::badPublishTime($e->getMessage());
        }
    }

    /** @param array{type: ArticleType, movie_id: ?int} $data */
    private function assertValid(array $data): void
    {
        if ($data['type'] === ArticleType::Premiere && $data['movie_id'] === null) {
            throw InvalidArticleException::premiereWithoutMovie();
        }

        if ($data['movie_id'] !== null && ! Movie::query()->whereKey($data['movie_id'])->exists()) {
            throw InvalidArticleException::unknownMovie();
        }
    }

    /**
     * @param  array{type: ArticleType, title: string, excerpt: string, body: string, movie_id: ?int}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'title' => trim($data['title']),
            'excerpt' => trim($data['excerpt']),
            'body' => trim($data['body']),
            'movie_id' => $data['movie_id'],
        ];
    }

    /** @param array{status: ArticleStatus, published_at: ?CarbonImmutable} $data */
    private function publishedAt(array $data, ?Article $current = null): ?CarbonImmutable
    {
        if ($data['published_at'] !== null) {
            // Pułapka BN: do bazy tylko UTC.
            return $data['published_at']->utc();
        }

        if ($data['status'] !== ArticleStatus::Published) {
            return null;
        }

        // Opublikowany bez daty: zachowujemy pierwotny moment publikacji przy edycji.
        return $current?->published_at !== null
            ? CarbonImmutable::parse($current->published_at)->utc()
            : CarbonImmutable::now()->utc();
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 200, '') ?: 'artykul';
        $base = rtrim($base, '-');
        $slug = $base;

        for ($i = 2; Article::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
