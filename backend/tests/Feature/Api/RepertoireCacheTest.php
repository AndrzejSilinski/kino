<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Cache repertuaru w API (Etap 7, blok C): trafienia, inwalidacja generacjami,
 * dane "na żywo" poza cache i zmiana pośrednia ze schedulera.
 *
 * Store 'array' z serializacją — tak jak Redis (patrz CatalogCacheTest).
 * Zmiany "za plecami" cache robimy przez DB::table(), a nie przez modele:
 * to symuluje zmianę BEZ inwalidacji i dowodzi, że odpowiedź przyszła z cache.
 */
final class RepertoireCacheTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private Cinema $kino;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        Cache::flush();

        $this->createScreeningWithSeats(rows: 2, cols: 5);
        $this->kino = $this->hall->cinema;
    }

    private function dayOf(Screening $screening): string
    {
        return $screening->starts_at->copy()->setTimezone($this->kino->timezone)->toDateString();
    }

    private function dayUrl(string $date): string
    {
        return '/api/v1/cinemas/'.$this->kino->getRouteKey().'/screenings?date='.$date;
    }

    /** @return list<string> zapytania SQL wykonane w trakcie $request */
    private function queriesDuring(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    private function touchedScreeningsTable(array $queries): bool
    {
        return collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "screenings"'));
    }

    public function test_second_request_is_served_from_cache_with_identical_json(): void
    {
        $url = $this->dayUrl($this->dayOf($this->screening));
        $first = $this->getJson($url)->assertOk()->json();

        $queries = $this->queriesDuring(function () use ($url, &$second): void {
            $second = $this->getJson($url)->assertOk()->json();
        });

        $this->assertFalse($this->touchedScreeningsTable($queries), 'Trafienie w cache nie może czytać tabeli screenings.');
        $this->assertSame($first, $second);
    }

    /** Modele odtworzone z tablic mają rzutowania i relacje jak po zwykłym zapytaniu. */
    public function test_hydrated_screening_keeps_casts_relations_and_cinema_timezone(): void
    {
        $url = $this->dayUrl($this->dayOf($this->screening));
        $this->getJson($url)->assertOk();

        $item = $this->getJson($url)->assertOk()->json('data.0');

        $this->assertSame($this->screening->id, $item['id']);
        $this->assertSame(
            $this->screening->starts_at->copy()->setTimezone($this->kino->timezone)->toIso8601String(),
            $item['starts_at'],
        );
        $this->assertSame('2d', $item['projection_type']);
        $this->assertSame('2D', $item['projection_type_label']);
        $this->assertSame($this->hall->name, $item['hall']['name']);
        $this->assertSame($this->screening->movie->title, $item['movie']['title']);
        $this->assertSame(['total' => 10, 'taken' => 0, 'available' => 10], $item['seats']);
        $this->assertTrue($item['is_bookable']);
    }

    public function test_movie_change_is_invisible_until_movies_generation_is_bumped(): void
    {
        $url = $this->dayUrl($this->dayOf($this->screening));
        $oldTitle = $this->getJson($url)->json('data.0.movie.title');

        DB::table('movies')->where('id', $this->screening->movie_id)->update(['title' => 'Nowy tytuł']);
        $this->assertSame($oldTitle, $this->getJson($url)->json('data.0.movie.title'));

        app(CatalogCache::class)->bump(CatalogCache::MOVIES);
        $this->assertSame('Nowy tytuł', $this->getJson($url)->json('data.0.movie.title'));
    }

    public function test_cinema_generation_invalidates_only_that_cinema(): void
    {
        $url = $this->dayUrl($this->dayOf($this->screening));
        $this->getJson($url)->assertOk();

        DB::table('halls')->where('id', $this->hall->id)->update(['name' => 'Sala po zmianie']);

        app(CatalogCache::class)->bump(CatalogCache::cinema($this->kino->id + 1000));
        $this->assertNotSame('Sala po zmianie', $this->getJson($url)->json('data.0.hall.name'));

        app(CatalogCache::class)->bump(CatalogCache::cinema($this->kino->id));
        $this->assertSame('Sala po zmianie', $this->getJson($url)->json('data.0.hall.name'));
    }

    /** Sprzedaż i blokady nie unieważniają cache — liczniki są zawsze na żywo. */
    public function test_seat_counts_and_sold_out_flag_are_live_without_invalidation(): void
    {
        $url = $this->dayUrl($this->dayOf($this->screening));
        $this->assertSame(0, $this->getJson($url)->json('data.0.seats.taken'));

        $this->seatLocks()->lock($this->screening, $this->seatIds, str_repeat('a', 32));

        $item = $this->getJson($url)->assertOk()->json('data.0');
        $this->assertSame(['total' => 10, 'taken' => 10, 'available' => 0], $item['seats']);
        $this->assertTrue($item['is_sold_out']);
        $this->assertFalse($item['is_bookable']);
    }

    /** Zmiana pośrednia: scheduler kończy seans jednym UPDATE, a repertuar w cache nie może go dalej pokazywać. */
    public function test_finished_screening_disappears_from_cached_day_right_after_scheduler(): void
    {
        $hall = Hall::factory()->for($this->kino)->create();
        $startsAt = CarbonImmutable::now()->subMinutes(200);
        $ended = Screening::factory()->for($hall)->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
        ]);

        $url = $this->dayUrl($this->dayOf($ended));
        $this->assertContains($ended->id, $this->getJson($url)->json('data.*.id'));

        $this->artisan('cinema:screenings:finish')->assertSuccessful();

        $this->assertNotContains($ended->id, $this->getJson($url)->json('data.*.id'));
    }

    /** Kalendarz z cache filtruje "teraz" przy odczycie: seans, który się zaczął, znika bez zapytania do bazy. */
    public function test_calendar_is_cached_but_filtered_by_current_time(): void
    {
        config(['cinema.catalog_cache.ttl_seconds' => 24 * 3600]);

        // Stała godzina zamiast slotu z ScreeningFactory: w pełnym zestawie licznik
        // slotów przesuwa seans o wiele dni, a podróż w czasie dalej niż TTL
        // wygasiłaby cache i test sprawdzałby bazę zamiast filtra przy odczycie.
        $startsAt = CarbonImmutable::now()->addHours(2)->startOfMinute();
        $this->screening->update([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
        ]);

        $url = '/api/v1/cinemas/'.$this->kino->getRouteKey().'/screening-dates';
        $day = $this->dayOf($this->screening);

        $this->assertContains($day, $this->getJson($url)->json('data.*.date'));

        $this->travelTo($this->screening->starts_at->copy()->addMinute());

        $queries = $this->queriesDuring(function () use ($url, &$dates): void {
            $dates = $this->getJson($url)->assertOk()->json('data.*.date');
        });

        $this->assertFalse($this->touchedScreeningsTable($queries), 'Kalendarz musi przyjść z cache.');
        $this->assertNotContains($day, $dates);
    }

    public function test_cinema_list_uses_cinemas_generation(): void
    {
        $this->kino->update(['is_active' => true, 'name' => 'Kino Przed']);

        $this->assertContains('Kino Przed', $this->getJson('/api/v1/cinemas')->json('data.*.cinemas.*.name'));

        DB::table('cinemas')->where('id', $this->kino->id)->update(['name' => 'Kino Po']);
        $this->assertContains('Kino Przed', $this->getJson('/api/v1/cinemas')->json('data.*.cinemas.*.name'));

        app(CatalogCache::class)->bump(CatalogCache::CINEMAS);
        $this->assertContains('Kino Po', $this->getJson('/api/v1/cinemas')->json('data.*.cinemas.*.name'));
    }
}
