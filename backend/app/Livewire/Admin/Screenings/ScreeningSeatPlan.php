<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Screenings;

use App\Enums\TicketStatus;
use App\Models\Screening;
use App\Models\Ticket;
use App\Services\SeatMapService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Podgląd planu sali seansu w panelu (Etap 7, blok I; wymóg 2.3): wolne, zablokowane,
 * sprzedane, niedostępne.
 *
 * Stan miejsc liczy ten sam SeatMapService co publiczne API (bez sesji klienta, więc
 * bez "twoich" blokad). Panel dokłada tylko numer rezerwacji przy sprzedanym miejscu —
 * cudza blokada pozostaje anonimowa, jak w API i w zdarzeniach WebSocket.
 *
 * ODŚWIEŻANIE: dla seansu w sprzedaży co 10 s (wire:poll). Zakończony, odwołany albo
 * trwający seans jest statyczny. Kanał WebSocket dla panelu dochodzi w bloku L.
 */
final class ScreeningSeatPlan extends Component
{
    #[Locked]
    public int $screeningId;

    public function mount(Screening $screening): void
    {
        $this->authorize('viewSeatPlan', $screening);

        $this->screeningId = $screening->id;
    }

    public function render(): View
    {
        $seatMaps = app(SeatMapService::class);
        $screening = Screening::query()
            ->with(['movie:id,title', 'hall:id,name,cinema_id,grid_rows,grid_cols', 'hall.cinema:id,name,slug,timezone'])
            ->findOrFail($this->screeningId);

        $this->authorize('viewSeatPlan', $screening);

        $plan = $seatMaps->build($screening, null);

        $references = Ticket::query()
            ->join('bookings', 'bookings.id', '=', 'tickets.booking_id')
            ->where('tickets.screening_id', $screening->id)
            ->where('tickets.status', '!=', TicketStatus::Cancelled)
            ->pluck('bookings.reference', 'tickets.seat_id')
            ->all();

        return view('livewire.admin.screenings.seat-plan', [
            'screening' => $screening,
            'plan' => $plan,
            'references' => $references,
            'live' => $screening->status->isBookable() && $screening->starts_at->isFuture(),
            'timezone' => $screening->hall->cinema->timezone,
        ])->title('Plan sali: '.$screening->movie->title);
    }
}
