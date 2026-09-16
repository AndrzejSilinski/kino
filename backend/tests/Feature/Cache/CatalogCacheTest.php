<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Models\Cinema;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * CatalogCache: generacje, podbicie po COMMIT, tylko tablice, zamek (Etap 7, blok C).
 *
 * STORE Z SERIALIZACJĄ: w phpunit.xml cache to 'array' z serialize = false —
 * obiekty leżą w pamięci i przechodzą bez błędu, a Redis zwróciłby je jako
 * __PHP_Incomplete_Class (pułapka BB). Włączamy serializację store'u 'array';
 * honoruje on to samo serializable_classes = false co RedisStore.
 */
final class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.array.serialize' => true,
            'cinema.catalog_cache.lock_wait_seconds' => 0,
        ]);
        Cache::forgetDriver('array');
        Cache::flush();
    }

    private function catalog(): CatalogCache
    {
        return app(CatalogCache::class);
    }

    public function test_value_is_served_from_cache_until_its_generation_is_bumped(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return ['obliczenie' => $calls];
        };

        $this->assertSame(['obliczenie' => 1], $this->catalog()->remember('probe', [CatalogCache::cinema(7)], $compute));
        $this->assertSame(['obliczenie' => 1], $this->catalog()->remember('probe', [CatalogCache::cinema(7)], $compute));
        $this->assertSame(1, $calls, 'Drugi odczyt musi pochodzić z cache.');

        $this->catalog()->bump(CatalogCache::cinema(7));

        $this->assertSame(['obliczenie' => 2], $this->catalog()->remember('probe', [CatalogCache::cinema(7)], $compute));
        $this->assertSame(2, $calls);
    }

    public function test_bumping_unrelated_generation_keeps_value(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): array {
            return ['obliczenie' => ++$calls];
        };

        $this->catalog()->remember('probe', [CatalogCache::cinema(7)], $compute);
        $this->catalog()->bump(CatalogCache::cinema(8), CatalogCache::MOVIES);
        $this->catalog()->remember('probe', [CatalogCache::cinema(7)], $compute);

        $this->assertSame(1, $calls);
    }

    public function test_epoch_invalidates_every_key(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): array {
            return ['obliczenie' => ++$calls];
        };

        $this->catalog()->remember('probe', [], $compute);
        $this->catalog()->remember('inny', [CatalogCache::MOVIES], $compute);
        $this->catalog()->bumpEpoch();
        $this->catalog()->remember('probe', [], $compute);
        $this->catalog()->remember('inny', [CatalogCache::MOVIES], $compute);

        $this->assertSame(4, $calls);
    }

    public function test_empty_array_is_a_cached_value_not_a_miss(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return [];
        };

        $this->catalog()->remember('pusto', [], $compute);
        $this->catalog()->remember('pusto', [], $compute);

        $this->assertSame(1, $calls);
    }

    public function test_bump_inside_transaction_takes_effect_only_after_commit(): void
    {
        DB::transaction(function (): void {
            $this->catalog()->bump(CatalogCache::MOVIES);

            // Czytelnik w oknie transakcji musi dalej widzieć starą generację,
            // inaczej zapisałby stare dane pod nowym kluczem na cały TTL.
            $this->assertSame(0, $this->catalog()->generation(CatalogCache::MOVIES));
        });

        $this->assertSame(1, $this->catalog()->generation(CatalogCache::MOVIES));
    }

    public function test_bump_is_discarded_on_rollback(): void
    {
        try {
            DB::transaction(function (): void {
                $this->catalog()->bump(CatalogCache::MOVIES);

                throw new RuntimeException('wycofanie zmiany');
            });
        } catch (RuntimeException) {
            // oczekiwane
        }

        $this->assertSame(0, $this->catalog()->generation(CatalogCache::MOVIES));
    }

    /** Dowód pułapki BB: store z serializacją oddaje model jako atrapę, bez wyjątku. */
    public function test_serializing_store_silently_returns_incomplete_class_for_model(): void
    {
        Cache::put('probe:model', Cinema::factory()->make(), 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('probe:model'));
    }

    public function test_catalog_rejects_objects_loudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('wartość.kino to App\Models\Cinema');

        $this->catalog()->remember('probe', [], fn (): array => ['kino' => Cinema::factory()->make()]);
    }

    /**
     * Zamek zajęty przez inny proces i brak czasu na czekanie: wartość liczymy
     * bez zapisu (fail-open). Nazwa zamka zależy od formatu klucza:
     * catalog:{nazwa}:g{epoka} — przy pustej liście generacji zostaje sama epoka.
     */
    public function test_lock_timeout_computes_without_storing(): void
    {
        Cache::lock('catalog:lock:catalog:probe:g0', 10)->get();

        $calls = 0;
        $compute = function () use (&$calls): array {
            return ['obliczenie' => ++$calls];
        };

        $this->assertSame(['obliczenie' => 1], $this->catalog()->remember('probe', [], $compute));
        $this->assertSame(['obliczenie' => 2], $this->catalog()->remember('probe', [], $compute));
    }
}
