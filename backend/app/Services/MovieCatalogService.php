<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Movie;
use App\Support\CatalogCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as PagePaginator;

/**
 * Publiczna lista filmów (Etap 7, blok F; wymóg 1.7 zadania: cache listy filmów).
 *
 * W CACHE jeden klucz z WSZYSTKIMI aktywnymi filmami jako tablicami atrybutów
 * (generacja MOVIES), a stronę wycinamy w PHP — jak repertuar dnia. Klucz na
 * każdą kombinację ?page i ?per_page dawałby nieograniczoną liczbę kluczy,
 * którą może napompować każdy, kto zmienia parametry w adresie.
 *
 * W cache NIE MA adresów URL plakatów: zasób API buduje je przy odczycie.
 * Adres zbudowany z nagłówka Host jednego żądania i zapisany w cache
 * trafiłby do wszystkich klientów (zatrucie cache).
 */
final class MovieCatalogService
{
    public const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /** @return LengthAwarePaginator<int, Movie> */
    public function activeMovies(int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $rows = $this->catalog->remember(
            'movies:active',
            [CatalogCache::MOVIES],
            fn (): array => Movie::query()
                ->active()
                // Najnowsze premiery najpierw; filmy bez daty na końcu; id ustala kolejność remisów.
                ->orderByRaw('premiere_date DESC NULLS LAST')
                ->orderBy('title')
                ->orderBy('id')
                ->get()
                ->map(fn (Movie $movie): array => $movie->getAttributes())
                ->all(),
        );

        $page = PagePaginator::resolveCurrentPage();

        return (new PagePaginator(
            Movie::hydrate(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            count($rows),
            $perPage,
            $page,
            ['path' => PagePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ))->withQueryString();
    }
}
