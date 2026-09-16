<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Cache katalogu w Redisie z inwalidacją przez LICZNIKI GENERACJI (Etap 7, blok C).
 *
 * KLUCZ = nazwa + numery generacji, od których zależą dane, np.
 *   catalog:repertoire:day:c3:2026-09-20:g2.5.11   (epoka . kino 3 . filmy)
 * Inwalidacja to increment() licznika — w Redisie jedno INCRBY: atomowe, O(1),
 * bez KEYS/SCAN i bez tagów. Stare klucze przestają być czytane i znikają po
 * TTL. TTL jest zabezpieczeniem i sprzątaniem, a NIE głównym mechanizmem.
 *
 * KOLEJNOŚĆ, na której opiera się poprawność:
 * 1. Generacje czytamy PRZED zapytaniem do bazy (key() w remember()).
 * 2. Podbijamy je PO COMMIT (bump() przez DB::afterCommit). Gdyby podbicie
 *    szło przed COMMIT, czytelnik w oknie transakcji policzyłby STARE dane pod
 *    NOWĄ generacją i zapisał je na cały TTL. Po ROLLBACK nic się nie podbija.
 *
 * EPOKA: generacja wspólna dla wszystkich kluczy. Podbija ją DatabaseSeeder —
 * po migrate:fresh --seed identyfikatory kin i seansów zaczynają się od nowa,
 * a stare klucze z tymi samymi id wskazywałyby nieistniejące dane.
 *
 * TYLKO TABLICE I SKALARY: config/cache.php ma serializable_classes = false
 * (Laravel 13), więc model zapisany w Redisie wraca po cichu jako
 * __PHP_Incomplete_Class — bez wyjątku (pułapka BB). assertPlain() zamienia
 * tę cichą awarię w głośny błąd już przy zapisie.
 *
 * STAMPEDE: po podbiciu generacji wszyscy czytelnicy trafiają w pusty klucz.
 * Liczy tylko ten, kto zdobędzie zamek; reszta czeka (lock_wait_seconds)
 * i czyta gotowy wynik. Brak zamka w czasie = liczymy bez zapisu (fail-open,
 * jak bezpiecznik Reverba): klient dostaje dane, cache dogoni przy następnym
 * żądaniu.
 */
final class CatalogCache
{
    public const EPOCH = 'epoch';

    public const CINEMAS = 'cinemas';

    public const MOVIES = 'movies';

    public const ARTICLES = 'articles';

    private const PREFIX = 'catalog:';

    public function __construct(
        private readonly Cache $cache,
    ) {}

    /** Generacja danych jednego kina: sale, układy, seanse, cenniki. */
    public static function cinema(int $cinemaId): string
    {
        return 'cinema:'.$cinemaId;
    }

    /**
     * Wartość z cache albo policzona i zapisana.
     *
     * @template T
     *
     * @param  list<string>  $generations  generacje, od których zależą dane (epoka dochodzi sama)
     * @param  Closure(): T  $compute  zapytanie do bazy; musi zwrócić wyłącznie tablice i skalary
     * @return T
     */
    public function remember(string $name, array $generations, Closure $compute): mixed
    {
        // 1. Generacje PRZED zapytaniem do bazy — patrz nagłówek klasy.
        $key = $this->key($name, $generations);

        $hit = $this->cache->get($key);

        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $hit['v'];
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->computeAndStore($key, $compute);
        }

        try {
            return $store->lock(self::PREFIX.'lock:'.$key, $this->lockSeconds())
                ->block($this->lockWaitSeconds(), function () use ($key, $compute): mixed {
                    // Ktoś mógł policzyć wartość, gdy czekaliśmy na zamek.
                    $again = $this->cache->get($key);

                    return is_array($again) && array_key_exists('v', $again)
                        ? $again['v']
                        : $this->computeAndStore($key, $compute);
                });
        } catch (LockTimeoutException) {
            $value = $compute();
            $this->assertPlain($value);

            return $value;
        }
    }

    /**
     * Podbija generacje PO COMMIT bieżącej transakcji; poza transakcją od razu.
     *
     * Błąd cache po COMMIT nie może zamienić udanej zmiany w błąd 500:
     * logujemy ostrzeżenie (bez komunikatu wyjątku, decyzja 62), a nieaktualne
     * dane wygasną po TTL.
     */
    public function bump(string ...$generations): void
    {
        $generations = array_values(array_unique($generations));

        if ($generations === []) {
            return;
        }

        DB::afterCommit(function () use ($generations): void {
            try {
                foreach ($generations as $generation) {
                    $this->cache->increment(self::PREFIX.'gen:'.$generation);
                }
            } catch (Throwable $e) {
                Log::warning('Nie udało się unieważnić cache katalogu.', [
                    'generations' => $generations,
                    'exception' => $e::class,
                ]);
            }
        });
    }

    /** Unieważnia cały katalog naraz (DatabaseSeeder). */
    public function bumpEpoch(): void
    {
        $this->bump(self::EPOCH);
    }

    /** Bieżący numer generacji; 0, gdy licznik jeszcze nie istnieje. */
    public function generation(string $generation): int
    {
        return (int) ($this->cache->get(self::PREFIX.'gen:'.$generation) ?? 0);
    }

    public function ttlSeconds(): int
    {
        return max(1, (int) config('cinema.catalog_cache.ttl_seconds', 600));
    }

    /** @param list<string> $generations */
    private function key(string $name, array $generations): string
    {
        $names = array_values(array_unique([self::EPOCH, ...$generations]));
        $keys = array_map(static fn (string $generation): string => self::PREFIX.'gen:'.$generation, $names);

        // Jedno MGET zamiast osobnego GET na każdą generację.
        $values = $this->cache->many($keys);

        $numbers = array_map(static fn (string $key): int => (int) ($values[$key] ?? 0), $keys);

        return self::PREFIX.$name.':g'.implode('.', $numbers);
    }

    private function computeAndStore(string $key, Closure $compute): mixed
    {
        $value = $compute();
        $this->assertPlain($value);

        // Opakowanie ['v' => ...] odróżnia zapisane null albo [] od braku klucza.
        $this->cache->put($key, ['v' => $value], $this->ttlSeconds());

        return $value;
    }

    private function assertPlain(mixed $value, string $path = 'wartość'): void
    {
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                $this->assertPlain($item, $path.'.'.$index);
            }

            return;
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        throw new LogicException(sprintf(
            'Cache katalogu przyjmuje wyłącznie tablice i skalary (serializable_classes = false), a %s to %s.',
            $path,
            get_debug_type($value),
        ));
    }

    private function lockSeconds(): int
    {
        return max(1, (int) config('cinema.catalog_cache.lock_seconds', 10));
    }

    private function lockWaitSeconds(): int
    {
        return max(0, (int) config('cinema.catalog_cache.lock_wait_seconds', 2));
    }
}
