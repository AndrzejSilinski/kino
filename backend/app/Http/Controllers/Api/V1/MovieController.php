<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MovieIndexRequest;
use App\Http\Resources\V1\MovieListItemResource;
use App\Services\MovieCatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Filmy w repertuarze sieci (Etap 7, blok F).
 */
class MovieController extends Controller
{
    public function __construct(
        private readonly MovieCatalogService $movies,
    ) {}

    /**
     * Aktywne filmy, stronicowane. Najnowsze premiery najpierw.
     *
     * Dane z cache Redis (generacja MOVIES), unieważnianego po każdej zmianie
     * filmu w panelu.
     */
    public function index(MovieIndexRequest $request): AnonymousResourceCollection
    {
        return MovieListItemResource::collection($this->movies->activeMovies($request->perPage()));
    }
}
