<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Services\Admin\MovieAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/movies (Etap 7, blok F): tylko aktywne, stronicowanie, cache z inwalidacją.
 *
 * Store 'array' z serializacją — jak Redis (patrz CatalogCacheTest). Zmiana
 * przez DB::table() omija serwis i inwalidację: dowodzi, że odpowiedź była z cache.
 */
final class MovieListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        Cache::flush();
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

    public function test_lists_active_movies_newest_premiere_first_without_description(): void
    {
        Movie::factory()->create(['title' => 'Starszy', 'premiere_date' => '2026-01-10']);
        Movie::factory()->create(['title' => 'Bez daty', 'premiere_date' => null]);
        Movie::factory()->create(['title' => 'Nowszy', 'premiere_date' => '2026-09-01', 'poster_path' => 'posters/abc.jpg']);
        Movie::factory()->inactive()->create(['title' => 'Wycofany']);

        $response = $this->getJson('/api/v1/movies')->assertOk();

        $this->assertSame(['Nowszy', 'Starszy', 'Bez daty'], $response->json('data.*.title'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertArrayNotHasKey('description', $response->json('data.0'));
        $this->assertStringEndsWith('/storage/posters/abc.jpg', (string) $response->json('data.0.poster_url'));
        $this->assertNull($response->json('data.1.poster_url'));
    }

    public function test_pagination_and_parameter_validation(): void
    {
        Movie::factory()->count(5)->create();

        $this->getJson('/api/v1/movies?per_page=2&page=3')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/v1/movies?page=9')->assertOk()->assertJsonCount(0, 'data');

        foreach (['page=0', 'page=abc', 'per_page=51', 'per_page=0'] as $query) {
            $this->getJson('/api/v1/movies?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }
    }

    public function test_second_request_comes_from_cache_and_panel_change_invalidates_it(): void
    {
        $movie = Movie::factory()->create(['title' => 'Przed zmianą', 'age_rating' => '12']);
        $this->getJson('/api/v1/movies')->assertOk();

        $queries = $this->queriesDuring(fn () => $this->getJson('/api/v1/movies?page=1&per_page=5')->assertOk());
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "movies"')), 'Inna strona tej samej listy też z cache.');

        // Zmiana za plecami cache: odpowiedź się nie zmienia.
        DB::table('movies')->where('id', $movie->id)->update(['title' => 'Zmiana bez serwisu']);
        $this->getJson('/api/v1/movies')->assertJsonPath('data.0.title', 'Przed zmianą');

        // Zmiana przez serwis panelu podbija generację po COMMIT.
        app(MovieAdminService::class)->update($movie->fresh(), [
            'title' => 'Po zmianie w panelu',
            'original_title' => null,
            'description' => 'Opis.',
            'duration_minutes' => $movie->duration_minutes,
            'age_rating' => '12',
            'genres' => ['Dramat'],
            'premiere_date' => null,
        ]);
        $this->getJson('/api/v1/movies')->assertJsonPath('data.0.title', 'Po zmianie w panelu');

        app(MovieAdminService::class)->setActive($movie->fresh(), false);
        $this->getJson('/api/v1/movies')->assertJsonCount(0, 'data');
    }

    public function test_poster_url_follows_each_request_host_not_the_cached_one(): void
    {
        Movie::factory()->create(['poster_path' => 'posters/abc.jpg']);

        $this->getJson('http://atak.example/api/v1/movies')
            ->assertJsonPath('data.0.poster_url', 'http://atak.example/storage/posters/abc.jpg');

        $this->getJson('http://localhost/api/v1/movies')
            ->assertJsonPath('data.0.poster_url', 'http://localhost/storage/posters/abc.jpg');
    }
}
