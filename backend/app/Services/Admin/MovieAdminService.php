<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ScreeningStatus;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Movie;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Filmy i plakaty w panelu (Etap 7, blok F).
 *
 * NIE MA USUWANIA: movies -> screenings ma ON DELETE RESTRICT, a bilety muszą
 * wskazywać film także po latach. Film się wyłącza; wyłączenie i zmiana czasu
 * trwania są zablokowane, dopóki film ma nadchodzące seanse.
 *
 * PLAKAT: plik i wiersz w bazie to dwa zasoby bez wspólnej transakcji.
 * 1. Obraz przetwarzamy i zapisujemy PRZED transakcją — bez trzymania blokady
 *    wiersza w czasie pracy GD i zapisu na dysk.
 * 2. Nowa nazwa pliku przy każdej zmianie (posters/{ulid}.jpg), nigdy nadpisanie:
 *    przeglądarki i CDN mogą trzymać stary adres w cache, a do COMMIT stary
 *    plik jest nadal tym, na który wskazuje baza.
 * 3. ROLLBACK albo wyjątek -> usuwamy NOWY plik. COMMIT -> usuwamy STARY
 *    (DB::afterCommit). Awaria między zapisem pliku a COMMIT zostawia osierocony
 *    plik — jest nieszkodliwy, a nie odwrotnie: baza nigdy nie wskazuje pliku,
 *    którego nie ma.
 *
 * INWALIDACJA CACHE po COMMIT: generacja MOVIES — lista filmów w API i wiersze
 * repertuaru dnia (tytuł, plakat, czas trwania).
 */
final class MovieAdminService
{
    /** Polskie kategorie wiekowe (komentarz w migracji tabeli movies). */
    public const AGE_RATINGS = ['B/O', '7', '12', '15', '16', '18'];

    public const POSTER_DIRECTORY = 'posters';

    /** Okno bezpieczeństwa sprzątania sierot (godziny) — patrz pruneOrphanPosters(). */
    public const ORPHAN_GRACE_HOURS = 24;

    public function __construct(
        private readonly CatalogCache $catalog,
        private readonly PosterImageProcessor $posters,
    ) {}

    /**
     * @param  array{title: string, original_title: ?string, description: string, duration_minutes: int, age_rating: string, genres: list<string>, premiere_date: ?string}  $data
     * @param  ?string  $posterSource  ścieżka do wgranego pliku albo null
     */
    public function create(array $data, ?string $posterSource = null): Movie
    {
        $newPoster = $posterSource !== null ? $this->storePoster($posterSource) : null;

        try {
            return DB::transaction(function () use ($data, $newPoster): Movie {
                // Szeregujemy tworzenie filmów: dwa formularze z tym samym tytułem
                // dostałyby ten sam wolny slug i drugi INSERT rozbiłby się o UNIQUE.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['movies:create']);

                $movie = new Movie;
                $movie->fill([...$this->attributes($data), 'slug' => $this->uniqueSlug($data['title'])]);
                $movie->poster_path = $newPoster;
                $movie->is_active = true;
                $movie->save();

                $this->catalog->bump(CatalogCache::MOVIES);

                return $movie;
            });
        } catch (Throwable $e) {
            $this->deletePoster($newPoster);

            throw $e;
        }
    }

    /**
     * @param  array{title: string, original_title: ?string, description: string, duration_minutes: int, age_rating: string, genres: list<string>, premiere_date: ?string}  $data
     *
     * @throws StructureChangeBlockedException zmiana czasu trwania przy nadchodzących seansach
     */
    public function update(Movie $movie, array $data, ?string $posterSource = null, bool $removePoster = false): Movie
    {
        $newPoster = $posterSource !== null ? $this->storePoster($posterSource) : null;

        try {
            return DB::transaction(function () use ($movie, $data, $newPoster, $removePoster): Movie {
                $fresh = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

                if ($data['duration_minutes'] !== $fresh->duration_minutes && ($upcoming = $this->upcomingScreenings($fresh)) > 0) {
                    throw StructureChangeBlockedException::movieDurationChange($upcoming);
                }

                $oldPoster = $fresh->poster_path;
                $fresh->fill($this->attributes($data));

                if ($newPoster !== null) {
                    $fresh->poster_path = $newPoster;
                } elseif ($removePoster) {
                    $fresh->poster_path = null;
                }

                // Usunięcie starego pliku rejestrujemy PRZED save(): wykona się dopiero po
                // COMMIT, a przy ROLLBACK (także z błędu w save()) Laravel je porzuca.
                if ($oldPoster !== null && $oldPoster !== $fresh->poster_path) {
                    DB::afterCommit(fn () => $this->deletePoster($oldPoster));
                }

                $fresh->save();

                $this->catalog->bump(CatalogCache::MOVIES);

                return $fresh;
            });
        } catch (Throwable $e) {
            $this->deletePoster($newPoster);

            throw $e;
        }
    }

    /**
     * @throws StructureChangeBlockedException przy wyłączaniu filmu z nadchodzącymi seansami
     */
    public function setActive(Movie $movie, bool $active): Movie
    {
        return DB::transaction(function () use ($movie, $active): Movie {
            $fresh = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            if (! $active && ($upcoming = $this->upcomingScreenings($fresh)) > 0) {
                throw StructureChangeBlockedException::movieDeactivation($upcoming);
            }

            $fresh->is_active = $active;
            $fresh->save();

            $this->catalog->bump(CatalogCache::MOVIES);

            return $fresh;
        });
    }

    /** Seanse zaplanowane, które się jeszcze nie skończyły — także trwające. */
    public function upcomingScreenings(Movie $movie): int
    {
        return $movie->screenings()
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '>', CarbonImmutable::now())
            ->count();
    }

    /**
     * @param  array{title: string, original_title: ?string, description: string, duration_minutes: int, age_rating: string, genres: list<string>, premiere_date: ?string}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'original_title' => $data['original_title'],
            'description' => $data['description'],
            'duration_minutes' => $data['duration_minutes'],
            'age_rating' => $data['age_rating'],
            'genres' => array_values($data['genres']),
            'premiere_date' => $data['premiere_date'],
        ];
    }

    /** Przetwarza obraz i zapisuje go pod nową, niezgadywalną nazwą. */
    private function storePoster(string $source): string
    {
        $bytes = $this->posters->toJpeg($source);
        $path = self::POSTER_DIRECTORY.'/'.Str::lower((string) Str::ulid()).'.jpg';

        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new RuntimeException('Nie udało się zapisać plakatu na dysku.');
        }

        return $path;
    }

    /**
     * Sprzątanie osieroconych plakatów (Etap 10, blok C2; zapowiedź z Etapu 7).
     *
     * Sierota powstaje, gdy proces padnie między zapisem pliku a COMMIT (wyjątek sprząta sam,
     * zabicie procesu — nie) albo gdy usunięcie starego pliku po COMMIT się nie uda. Usuwamy
     * plik tylko wtedy, gdy JEDNOCZEŚNIE:
     *  - nazwa ma kształt, który nadaje storePoster() (posters/<ulid>.jpg) — cudzych plików
     *    w katalogu nie ruszamy nawet przez pomyłkę w konfiguracji,
     *  - żaden film nie wskazuje go w poster_path,
     *  - jest starszy niż $olderThanHours: plik zapisany przed trwającą właśnie transakcją
     *    jeszcze nie ma wiersza, który go wskazuje — okno bezpieczeństwa to kilka godzin,
     *    a nie milisekundy, bo koszt pomyłki (utracony plakat) jest dużo wyższy niż koszt
     *    zostawienia sieroty do jutra.
     *
     * @return array{checked: int, referenced: int, fresh: int, foreign: int, orphans: list<string>, deleted: int}
     */
    public function pruneOrphanPosters(int $olderThanHours = self::ORPHAN_GRACE_HOURS, bool $dryRun = false): array
    {
        $disk = Storage::disk('public');
        $referenced = array_flip(Movie::query()->whereNotNull('poster_path')->pluck('poster_path')->all());
        $cutoff = CarbonImmutable::now()->subHours(max(1, $olderThanHours))->getTimestamp();
        $result = ['checked' => 0, 'referenced' => 0, 'fresh' => 0, 'foreign' => 0, 'orphans' => [], 'deleted' => 0];

        foreach ($disk->files(self::POSTER_DIRECTORY) as $path) {
            $result['checked']++;

            if (preg_match('#\A'.self::POSTER_DIRECTORY.'/[0-9a-z]{26}\.jpg\z#', $path) !== 1) {
                $result['foreign']++;
            } elseif (isset($referenced[$path])) {
                $result['referenced']++;
            } elseif ($disk->lastModified($path) > $cutoff) {
                $result['fresh']++;
            } else {
                $result['orphans'][] = $path;
                if (! $dryRun && $disk->delete($path)) {
                    $result['deleted']++;
                }
            }
        }

        return $result;
    }

    /**
     * Usuwa plik plakatu. Błąd tylko logujemy: po COMMIT nie może zamienić udanej
     * zmiany w błąd 500, a osierocony plik niczego nie psuje.
     */
    private function deletePoster(?string $path): void
    {
        // Tylko nasze pliki: ścieżka z bazy nie może wskazać niczego poza posters/.
        if ($path === null || ! str_starts_with($path, self::POSTER_DIRECTORY.'/') || str_contains($path, '..')) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            Log::warning('Nie udało się usunąć pliku plakatu.', ['path' => $path, 'exception' => $e::class]);
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $base = $base === '' ? 'film' : Str::limit($base, 200, '');
        $slug = $base;

        for ($suffix = 2; Movie::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
