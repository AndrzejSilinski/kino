<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CinemaResource;
use App\Models\Cinema;
use App\Services\RepertoireService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Pierwszy ekran ścieżki zakupowej — wybór kina.
 */
class CinemaController extends Controller
{
    public function __construct(
        private readonly RepertoireService $repertoire,
    ) {}

    /**
     * Lista kin pogrupowana po miastach.
     *
     * Bez paginacji i to jest decyzja, nie przeoczenie: sieć kin ma
     * kilkanaście lokalizacji, a ekran wyboru pokazuje wszystkie naraz.
     * Stronicowanie zmusiłoby klienta do doczytywania stron tylko po to,
     * żeby zbudować pełną listę miast.
     */
    public function index(): JsonResponse
    {
        $groups = $this->repertoire->cinemasByCity()->map(fn (array $group): array => [
            'city' => $group['city'],
            'cinemas' => $group['cinemas']->map(fn (Cinema $cinema) => new CinemaResource($cinema)),
        ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * Szczegóły kina.
     *
     * Nieczynne kino zwraca 404 RESOURCE_NOT_FOUND, a nie 403. Powód:
     * dla klienta nieczynne kino po prostu nie istnieje, a 403
     * potwierdzałoby, że pod tym adresem coś jest. Rzucamy
     * ModelNotFoundException, nie abort(404) — dzięki temu renderer
     * z kroku 3.2 nada kod RESOURCE_NOT_FOUND zamiast ENDPOINT_NOT_FOUND.
     */
    public function show(Cinema $cinema): CinemaResource
    {
        if (! $cinema->is_active) {
            throw (new ModelNotFoundException())->setModel(Cinema::class);
        }

        return new CinemaResource($cinema);
    }
}
