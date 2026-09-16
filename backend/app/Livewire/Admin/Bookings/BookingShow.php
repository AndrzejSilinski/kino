<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Bookings;

use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Szczegóły rezerwacji w panelu (Etap 7, blok I). Anulowanie z powodem dojdzie w bloku K.
 *
 * Identyfikator płatności Stripe pokazujemy tylko w końcówce: wystarcza do znalezienia
 * płatności w panelu Stripe, a pełny identyfikator nie jest potrzebny obsłudze.
 */
final class BookingShow extends Component
{
    #[Locked]
    public int $bookingId;

    public function mount(Booking $booking): void
    {
        $this->authorize('viewInPanel', $booking);

        $this->bookingId = $booking->id;
    }

    public function render(): View
    {
        $booking = Booking::query()
            ->with([
                'user:id,name,email',
                'cancelledBy:id,name',
                'screening.movie:id,title,duration_minutes',
                'screening.hall:id,name,cinema_id',
                'screening.hall.cinema:id,name,city,slug,timezone',
                'tickets' => fn ($tickets) => $tickets->orderBy('id'),
                'tickets.seat:id,row_label,seat_number,price_category_id',
                'tickets.seat.priceCategory:id,name',
            ])
            ->findOrFail($this->bookingId);

        $this->authorize('viewInPanel', $booking);

        return view('livewire.admin.bookings.show', [
            'booking' => $booking,
            'isAdmin' => auth()->user()->isAdmin(),
            'timezone' => $booking->screening->hall->cinema->timezone,
            'statuses' => BookingIndex::STATUS_LABELS,
        ])->title('Rezerwacja '.$booking->reference);
    }
}
