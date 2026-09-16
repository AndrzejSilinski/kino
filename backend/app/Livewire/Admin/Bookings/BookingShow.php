<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Bookings;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BookingCancellationException;
use App\Models\Booking;
use App\Services\PaymentService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Szczegóły rezerwacji w panelu (Etap 7, blok I) i anulowanie z powodem (blok K).
 *
 * Anulowanie: komponent zbiera powód i woła PaymentService. Czy rezerwację da się
 * anulować, rozstrzyga serwis pod blokadą — podpowiedź w widoku to tylko wygoda,
 * bo stan może się zmienić między wyświetleniem strony a kliknięciem.
 *
 * Identyfikator płatności Stripe pokazujemy tylko w końcówce: wystarcza do znalezienia
 * płatności w panelu Stripe, a pełny identyfikator nie jest potrzebny obsłudze.
 */
final class BookingShow extends Component
{
    #[Locked]
    public int $bookingId;

    /** Powód anulowania — notatka wewnętrzna, widoczna tylko dla administratorów. */
    public string $reason = '';

    /** Komunikat po anulowaniu (wynik rozliczenia płatności). */
    #[Locked]
    public ?string $notice = null;

    public function mount(Booking $booking): void
    {
        $this->authorize('viewInPanel', $booking);

        $this->bookingId = $booking->id;
    }

    public function cancelBooking(PaymentService $payments): void
    {
        $booking = Booking::query()->findOrFail($this->bookingId);
        $this->authorize('cancel', $booking);

        $this->reason = trim($this->reason);
        $this->validate(
            ['reason' => ['required', 'string', 'min:'.BookingCancellationException::REASON_MIN, 'max:'.BookingCancellationException::REASON_MAX]],
            attributes: ['reason' => 'powód anulowania'],
        );

        try {
            $outcome = $payments->cancelByAdmin($booking, auth()->user(), $this->reason);
        } catch (BookingCancellationException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->reset('reason');
        $this->notice = $outcome->message();
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

        $isAdmin = auth()->user()->isAdmin();

        return view('livewire.admin.bookings.show', [
            'booking' => $booking,
            'isAdmin' => $isAdmin,
            'canCancel' => $isAdmin && in_array($booking->status, [BookingStatus::Pending, BookingStatus::Paid], true),
            'cancelBlocked' => $this->cancelBlockedReason($booking),
            'timezone' => $booking->screening->hall->cinema->timezone,
            'statuses' => BookingIndex::STATUS_LABELS,
        ])->title('Rezerwacja '.$booking->reference);
    }

    /** Podpowiedź, dlaczego opłaconej rezerwacji nie da się już anulować. Te same komunikaty co serwis. */
    private function cancelBlockedReason(Booking $booking): ?string
    {
        if ($booking->status !== BookingStatus::Paid) {
            return null;
        }

        if ($booking->screening->starts_at->isPast()) {
            return BookingCancellationException::screeningStarted()->getMessage();
        }

        $used = $booking->tickets->where('status', TicketStatus::Used)->count();

        return $used > 0 ? BookingCancellationException::ticketsUsed($used)->getMessage() : null;
    }
}
