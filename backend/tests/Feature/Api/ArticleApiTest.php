<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Models\Movie;
use App\Models\User;
use App\Services\Admin\ArticleAdminService;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/articles i /articles/{slug} (Etap 7, blok M; wymóg 2.4).
 *
 * Store 'array' z serializacją — jak Redis (pułapka BB). Zmiana przez DB::table()
 * omija serwis i inwalidację: dowodzi, że odpowiedź była z cache.
 */
final class ArticleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'UTC'));
    }

    public function test_only_published_articles_whose_time_has_come_newest_first(): void
    {
        $movie = Movie::factory()->create(['title' => 'Diuna T', 'slug' => 'diuna-t']);
        Article::factory()->published('2026-09-30 08:00')->create(['title' => 'Starszy', 'slug' => 'starszy']);
        Article::factory()->premiere($movie)->published('2026-10-01 09:59')->create(['title' => 'Premiera', 'slug' => 'premiera']);
        Article::factory()->create(['title' => 'Szkic', 'slug' => 'szkic']);
        Article::factory()->published('2026-10-01 10:01')->create(['title' => 'Zaplanowany', 'slug' => 'zaplanowany']);

        $response = $this->getJson('/api/v1/articles')->assertOk();

        $this->assertSame(['Premiera', 'Starszy'], $response->json('data.*.title'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['slug' => 'diuna-t', 'title' => 'Diuna T'], $response->json('data.0.movie'));
        $this->assertSame('Nadchodzące premiery', $response->json('data.0.type_label'));
        $this->assertSame('2026-10-01T09:59:00+00:00', $response->json('data.0.published_at'));
        $this->assertSame(['slug', 'type', 'type_label', 'title', 'excerpt', 'published_at', 'movie'], array_keys($response->json('data.1')));

        $this->getJson('/api/v1/articles?type=premiere')->assertOk()->assertJsonPath('data.*.slug', ['premiera']);
        $this->getJson('/api/v1/articles?type=news&per_page=1&page=1')->assertOk()->assertJsonPath('meta.total', 1);

        foreach (['type=blog', 'per_page=51', 'page=0'] as $query) {
            $this->getJson('/api/v1/articles?'.$query)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        }

        foreach (['szkic', 'zaplanowany', 'nie-ma-takiego'] as $slug) {
            $this->getJson('/api/v1/articles/'.$slug)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        }
        $this->getJson('/api/v1/articles/Zly_Format')->assertNotFound();
    }

    public function test_scheduled_article_appears_at_its_time_without_invalidation(): void
    {
        Article::factory()->published('2026-10-01 10:05')->create(['slug' => 'o-dwunastej', 'body' => 'Treść **na czas**.']);

        $this->getJson('/api/v1/articles')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/articles/o-dwunastej')->assertNotFound();

        // Bez żadnej zmiany w bazie i bez podbicia generacji — tylko upływ czasu
        // (5 minut: krócej niż TTL cache, więc lista na pewno nie została przeliczona).
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:05', 'UTC'));
        $queries = $this->queriesDuring(fn () => $this->getJson('/api/v1/articles')->assertJsonCount(1, 'data'));
        $this->assertFalse($this->touchesArticles($queries), 'Lista z cache — filtr czasu liczony przy odczycie.');

        $this->getJson('/api/v1/articles/o-dwunastej')->assertOk()
            ->assertJsonPath('data.body_html', "<p>Treść <strong>na czas</strong>.</p>\n");
    }

    public function test_responses_come_from_cache_and_admin_changes_invalidate_them(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(ArticleAdminService::class);
        $article = $service->create($this->data('Przed zmianą', 'Stara treść.'), $admin);

        $this->getJson('/api/v1/articles')->assertJsonPath('data.0.title', 'Przed zmianą');
        $this->getJson('/api/v1/articles/'.$article->slug)->assertJsonPath('data.body_html', "<p>Stara treść.</p>\n");

        $queries = $this->queriesDuring(function () use ($article): void {
            $this->getJson('/api/v1/articles?page=1&per_page=5')->assertOk();
            $this->getJson('/api/v1/articles/'.$article->slug)->assertOk();
            $this->getJson('/api/v1/articles/nie-ma-takiego')->assertNotFound();
        });
        $this->assertFalse($this->touchesArticles($queries), 'Lista, treść i 404 bez zapytań do bazy.');

        // Zmiana za plecami cache: odpowiedź się nie zmienia.
        DB::table('articles')->where('id', $article->id)->update(['title' => 'Bez serwisu', 'body' => 'Bez serwisu.']);
        $this->getJson('/api/v1/articles/'.$article->slug)->assertJsonPath('data.title', 'Przed zmianą')->assertJsonPath('data.body_html', "<p>Stara treść.</p>\n");

        // Zmiana w panelu podbija generację po COMMIT — i lista, i treść są świeże. Slug bez zmian.
        $service->update($article, $this->data('Po zmianie', 'Nowa treść.'));
        $this->getJson('/api/v1/articles')->assertJsonPath('data.0.title', 'Po zmianie')->assertJsonPath('data.0.slug', $article->slug);
        $this->getJson('/api/v1/articles/'.$article->slug)->assertJsonPath('data.body_html', "<p>Nowa treść.</p>\n");

        // Cofnięcie do szkicu i usunięcie.
        $service->update($article, [...$this->data('Po zmianie', 'Nowa treść.'), 'status' => ArticleStatus::Draft, 'published_at' => null]);
        $this->getJson('/api/v1/articles/'.$article->slug)->assertNotFound();
        $service->update($article, $this->data('Znów publiczny', 'Treść.'));
        $this->getJson('/api/v1/articles')->assertJsonCount(1, 'data');
        $service->delete($article);
        $this->getJson('/api/v1/articles')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/articles/'.$article->slug)->assertNotFound();
    }

    public function test_rolled_back_change_does_not_invalidate_and_misses_do_not_create_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $article = app(ArticleAdminService::class)->create($this->data('Stały', 'Treść.'), $admin);
        $this->getJson('/api/v1/articles')->assertOk();
        $generation = app(CatalogCache::class)->generation(CatalogCache::ARTICLES);

        DB::beginTransaction();
        app(ArticleAdminService::class)->update($article, $this->data('Wycofany', 'Treść.'));
        DB::rollBack();

        $this->assertSame($generation, app(CatalogCache::class)->generation(CatalogCache::ARTICLES));
        $this->getJson('/api/v1/articles')->assertJsonPath('data.0.title', 'Stały');

        $store = Cache::store('array')->getStore();
        $keysBefore = count((fn () => $this->storage)->call($store));
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/articles/losowy-adres-'.$i)->assertNotFound();
        }
        $this->assertSame($keysBefore, count((fn () => $this->storage)->call($store)), 'Losowe adresy nie zakładają kluczy w cache.');
    }

    public function test_body_html_is_sanitized_in_the_api(): void
    {
        Article::factory()->published()->create(['slug' => 'zly', 'body' => "Tekst <script>alert(1)</script>\n\n[x](javascript:alert(1))"]);

        $html = (string) $this->getJson('/api/v1/articles/zly')->assertOk()->json('data.body_html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /** @return array{type: ArticleType, title: string, excerpt: string, body: string, movie_id: ?int, status: ArticleStatus, published_at: ?CarbonImmutable} */
    private function data(string $title, string $body): array
    {
        return ['type' => ArticleType::News, 'title' => $title, 'excerpt' => 'Zajawka.', 'body' => $body, 'movie_id' => null, 'status' => ArticleStatus::Published, 'published_at' => null];
    }

    /** @return list<string> */
    private function queriesDuring(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    /** @param list<string> $queries */
    private function touchesArticles(array $queries): bool
    {
        return collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"articles"'));
    }
}
