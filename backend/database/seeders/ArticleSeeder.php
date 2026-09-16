<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Models\Movie;
use App\Models\User;
use App\Support\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Przykładowe artykuły (Etap 7, blok M): dwie aktualności, premiera, szkic
 * i artykuł zaplanowany. Idempotentny (updateOrCreate po slugu) — można go
 * uruchomić osobno: php artisan db:seed --class=ArticleSeeder.
 */
class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@cinema.test')->first();
        $movie = Movie::query()->orderByRaw('premiere_date DESC NULLS LAST')->orderBy('id')->first();

        $articles = [
            ['slug' => 'nowe-fotele-w-sali-imax', 'type' => ArticleType::News, 'status' => ArticleStatus::Published, 'published_at' => now()->subDays(3),
                'title' => 'Nowe fotele w sali IMAX', 'excerpt' => 'Sala IMAX w Kinie Atlantyk przeszła modernizację — szersze fotele i więcej miejsca na nogi.',
                'body' => "## Co się zmieniło\n\n- szersze fotele z podłokietnikami,\n- większy odstęp między rzędami,\n- miejsca dla osób z niepełnosprawnością w **rzędzie A**.\n\nZapraszamy na seanse!"],
            ['slug' => 'bilety-kupisz-teraz-przez-blik', 'type' => ArticleType::News, 'status' => ArticleStatus::Published, 'published_at' => now()->subDay(),
                'title' => 'Bilety kupisz teraz przez BLIK', 'excerpt' => 'Do płatności kartą dołączył BLIK — bilety w kilka sekund.',
                'body' => 'Płatność BLIK działa w aplikacji i na stronie. Szczegóły w [pomocy](https://example.com/pomoc).'],
            ['slug' => 'program-festiwalu-szkic', 'type' => ArticleType::News, 'status' => ArticleStatus::Draft, 'published_at' => null,
                'title' => 'Program festiwalu (szkic)', 'excerpt' => 'Szkic — nie jest widoczny w API.', 'body' => 'Treść w przygotowaniu.'],
            ['slug' => 'maraton-filmowy-zapowiedz', 'type' => ArticleType::News, 'status' => ArticleStatus::Published, 'published_at' => now()->addDays(7),
                'title' => 'Maraton filmowy — zapowiedź', 'excerpt' => 'Zaplanowany: pojawi się w API za tydzień.', 'body' => 'Szczegóły wkrótce.'],
        ];

        if ($movie !== null) {
            $articles[] = ['slug' => 'premiera-'.$movie->slug, 'type' => ArticleType::Premiere, 'status' => ArticleStatus::Published, 'published_at' => now()->subHours(6),
                'title' => 'Premiera: '.$movie->title, 'excerpt' => 'Nadchodząca premiera w naszych kinach.', 'body' => "### {$movie->title}\n\nBilety już w sprzedaży.", 'movie_id' => $movie->id];
        }

        foreach ($articles as $data) {
            $article = Article::query()->firstOrNew(['slug' => $data['slug']]);
            $article->fill([
                'type' => $data['type'], 'title' => $data['title'], 'excerpt' => $data['excerpt'],
                'body' => $data['body'], 'movie_id' => $data['movie_id'] ?? null,
            ]);
            $article->status = $data['status'];
            $article->published_at = $data['published_at']?->utc();
            $article->author_id = $admin?->id;
            $article->save();
        }

        app(CatalogCache::class)->bump(CatalogCache::ARTICLES);
    }
}
