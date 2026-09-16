<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveBookingSession;
use App\Http\Requests\Api\V1\LockSeatsRequest;
use App\Models\Screening;
use App\Models\Seat;
use App\Services\CartPricingService;
use App\Services\SeatLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Koszyk: blokowanie i zwalnianie miejsc.
 *
 * Kontroler nie zawiera ani grama logiki współbieżności — cała siedzi
 * w SeatLockService z Etapu 2, nietkniętym przez ten krok. Tutaj jest
 * wyłącznie tłumaczenie HTTP na wywołania serwisu i z powrotem.
 *
 * Każda operacja zwraca AKTUALNY STAN KOSZYKA, a nie samo potwierdzenie.
 * Powód praktyczny: po zablokowaniu miejsca frontend i tak musi odświeżyć
 * listę i sumę, a osobne żądanie po każdym kliknięciu podwoiłoby ruch
 * na najgorętszym endpoincie systemu.
 */
class SeatLockController extends Controller
{
    public function __construct(
        private readonly SeatLockService $seatLocks,
        private readonly CartPricingService $cart,
    ) {}

    /** Moje blokady na tym seansie wraz z wyceną i czasem do wygaśnięcia. */
    public function index(Request $request, Screening $screening): JsonResponse
    {
        return $this->cartResponse($request, $screening, 200);
    }

    /**
     * Zablokuj miejsca. Operacja all-or-nothing.
     *
     * Konflikt kończy się wyjątkiem SeatsUnavailableException z serwisu,
     * który ApiExceptionRenderer zamienia na 409 z listą zajętych foteli
     * w polu context — dokładnie tym, czego frontend potrzebuje, żeby
     * przemalować plan sali bez dodatkowego zapytania.
     */
    public function store(LockSeatsRequest $request, Screening $screening): JsonResponse
    {
        $this->seatLocks->lock(
            $screening,
            $request->seatIds(),
            $this->sessionId($request),
            // Token bearer jest opcjonalny na tej trasie. Jeśli klient
            // jest zalogowany, wiążemy blokadę z kontem — przyda się
            // w Etapie 4 przy tworzeniu rezerwacji.
            $request->user('sanctum')?->id,
        );

        return $this->cartResponse($request, $screening, 201);
    }

    /**
     * Odkliknięcie jednego miejsca.
     *
     * Zwraca 200 z aktualnym koszykiem także wtedy, gdy blokady już nie było.
     * To nie jest niedbalstwo, tylko idempotencja: z punktu widzenia klienta stan
     * docelowy ("nie trzymam tego miejsca") został osiągnięty. Zwracanie
     * 404 zmuszałoby frontend do obsługi błędu, który nie jest błędem —
     * a podwójne kliknięcie zdarza się nieustannie.
     *
     * Etap 8, blok F: 200 z koszykiem zamiast 204. Po odkliknięciu klient i tak
     * potrzebuje nowej sumy i czasu wygaśnięcia; bez tego każde odkliknięcie
     * kosztowałoby dwa żądania z limitu 30/min na sesję (DELETE + GET).
     */
    public function destroy(Request $request, Screening $screening, Seat $seat): JsonResponse
    {
        $this->seatLocks->release(
            $screening,
            [$seat->id],
            $this->sessionId($request),
        );

        return $this->cartResponse($request, $screening, 200);
    }

    /**
     * Porzucenie całego koszyka ("Wyczyść wybór").
     *
     * Etap 8, blok F: SPA NIE wysyła tego przy zamknięciu karty. navigator.sendBeacon
     * nie ustawia nagłówka X-Session-Id, a fetch z keepalive w zdarzeniu pagehide
     * odpaliłby się także przy zwykłym odświeżeniu strony i zwolnił miejsca klientowi,
     * który tylko nacisnął F5. Porzucony koszyk zwalnia TTL blokad.
     * Zwraca 200 z (pustym) koszykiem — ten sam kształt co pozostałe operacje.
     */
    public function destroyAll(Request $request, Screening $screening): JsonResponse
    {
        $this->seatLocks->releaseSession(
            $screening,
            $this->sessionId($request),
        );

        return $this->cartResponse($request, $screening, 200);
    }

    /** Sesja zakupowa z middleware ResolveBookingSession. */
    private function sessionId(Request $request): string
    {
        return (string) $request->attributes->get(ResolveBookingSession::ATTRIBUTE);
    }

    /** Jeden kształt odpowiedzi dla GET i POST — jeden kontrakt dla klienta. */
    private function cartResponse(Request $request, Screening $screening, int $status): JsonResponse
    {
        $sessionId = $this->sessionId($request);

        return response()->json([
            'data' => $this->cart->forSession($screening, $sessionId),
            'meta' => [
                'session_id' => $sessionId,
                'screening_id' => $screening->id,
            ],
        ], $status);
    }
}
