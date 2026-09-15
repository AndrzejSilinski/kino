<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ScreeningDayRequest;
use App\Http\Resources\V1\ScreeningListItemResource;
use App\Http\Resources\V1\ScreeningResource;
use App\Models\Cinema;
use App\Models\Screening;
use App\Services\RepertoireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Kalendarz, repertuar dnia i szczegóły seansu.
 */
class ScreeningController extends Controller
{
    public function __construct(
        private readonly RepertoireService $repertoire,
    ) {}

    /**
     * Dni, w których kino ma dostępne seanse — dane dla kalendarza.
     *
     * Osobny, bardzo tani endpoint. W Etapie 7 będzie pierwszym
     * kandydatem do cache'a w Redisie: odpytywany przy każdym wejściu
     * na stronę kina, zmieniany raz na dobę przez admina.
     */
    public function dates(Cinema $cinema): JsonResponse
    {
        return response()->json([
            'data' => $this->repertoire->availableDates($cinema),
            'meta' => [
                'cinema_id' => $cinema->id,
                'timezone' => $cinema->timezone,
                'today' => now($cinema->timezone)->toDateString(),
            ],
        ]);
    }

    /**
     * Repertuar kina na wybrany dzień. Stronicowany.
     *
     * additional() dokłada date i timezone do sekcji meta obok danych
     * paginacji, żeby klient miał komplet kontekstu w jednej odpowiedzi.
     */
    public function index(ScreeningDayRequest $request, Cinema $cinema): AnonymousResourceCollection
    {
        $date = $request->dateOrToday($cinema->timezone);

        $screenings = $this->repertoire->screeningsForDay(
            $cinema,
            $date,
            $request->perPage(),
        );

        return ScreeningListItemResource::collection($screenings)->additional([
            'meta' => [
                'date' => $date,
                'timezone' => $cinema->timezone,
            ],
        ]);
    }

    /** Szczegóły seansu — film, sala, kino, cennik kategorii. */
    public function show(Screening $screening): ScreeningResource
    {
        return new ScreeningResource(
            $this->repertoire->screeningDetails($screening)
        );
    }
}
