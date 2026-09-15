<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveBookingSession;
use App\Http\Resources\V1\ScreeningResource;
use App\Models\Screening;
use App\Services\RepertoireService;
use App\Services\SeatMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan sali dla seansu — stan każdego miejsca.
 *
 * Kontroler jednoakcyjny (__invoke), bo to jedna operacja, która nie
 * należy do żadnego CRUD-a. Trasa wskazuje wtedy samą klasę, bez
 * podawania nazwy metody.
 *
 * Ten sam endpoint obsługuje dwa scenariusze z zadania:
 *   - wejście na ekran wyboru miejsc (sekcja 3.2)
 *   - odzyskanie pełnego stanu po zerwaniu WebSocketa (sekcja 1.3),
 *     gdzie klient najpierw pobiera stan przez REST, a dopiero potem
 *     subskrybuje kanał
 */
class SeatMapController extends Controller
{
    public function __construct(
        private readonly SeatMapService $seatMap,
        private readonly RepertoireService $repertoire,
    ) {}

    public function __invoke(Request $request, Screening $screening): JsonResponse
    {
        // Sesja zakupowa rozstrzyga, które blokady są "moje".
        // Ustawia ją middleware ResolveBookingSession przypięte do trasy.
        $sessionId = $request->attributes->get(ResolveBookingSession::ATTRIBUTE);

        $screening = $this->repertoire->screeningDetails($screening);
        $map = $this->seatMap->build($screening, $sessionId);

        return response()->json([
            'data' => [
                'screening' => new ScreeningResource($screening),
                'seats' => $map['seats'],
                'summary' => $map['summary'],
                // Wersja stanu miejsc: klient odrzuca zdarzenia WebSocket
                // z wersją <= tej wartości (Etap 6).
                'seat_state_version' => $map['version'],
            ],
            'meta' => [
                // Identyfikator sesji wraca też w ciele, nie tylko
                // w nagłówku X-Session-Id. Nagłówki bywają gubione przez
                // proxy i przez część klientów HTTP, a bez sesji klient
                // nie odróżni własnych blokad od cudzych.
                'session_id' => $sessionId,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
