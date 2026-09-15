<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Historia zamówień klienta.
 *
 * Dwie NIEZALEŻNE bariery chronią przed dostępem do cudzych danych:
 *
 *   1. index() zawęża zapytanie do własnych rekordów. Nawet gdyby
 *      Policy zawiodła, zapytanie fizycznie nie może zwrócić cudzej
 *      rezerwacji.
 *   2. show() pyta BookingPolicy o konkretny rekord. Bez tego
 *      wystarczyłby poprawny identyfikator w adresie, żeby obejść
 *      filtr z punktu 1 — klasyczna podatność IDOR.
 *
 * Trzecia, słabsza warstwa to sam kształt klucza: reference jest ULID-em,
 * więc nie da się go zgadnąć inkrementacją. To utrudnienie, nie
 * zabezpieczenie — ale w praktyce odcina skanowanie na ślepo.
 */
class BookingController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /** Lista własnych rezerwacji, od najnowszej. Stronicowana. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Booking::class);

        $bookings = Booking::query()
            ->where('user_id', $request->user()->id)
            ->with(['screening.movie', 'screening.hall.cinema'])
            ->withCount('tickets')
            ->latest('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    /** Szczegóły rezerwacji wraz z biletami. Chronione Policy. */
    public function show(Booking $booking): BookingResource
    {
        // Gate::authorize zamiast $this->authorize — od Laravela 11
        // kontroler bazowy nie ma już traitu AuthorizesRequests.
        // Niepowodzenie rzuca AuthorizationException, którą
        // ApiExceptionRenderer zamienia na 403 FORBIDDEN.
        Gate::authorize('view', $booking);

        $booking->load(['screening.movie', 'screening.hall.cinema', 'tickets.seat'])
            ->loadCount('tickets');

        // Bilety dostają rodzica bez dodatkowego zapytania: TicketResource
        // buduje z niego qr_url (decyzja 83).
        $booking->tickets->each(fn (Ticket $ticket) => $ticket->setRelation('booking', $booking));

        return new BookingResource($booking);
    }

    /** Rozmiar strony z ?per_page, przycięty do bezpiecznego zakresu. */
    private function perPage(Request $request): int
    {
        return max(1, min(
            $request->integer('per_page', self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        ));
    }
}
