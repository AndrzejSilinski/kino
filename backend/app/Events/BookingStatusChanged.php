<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\BookingStatus;
use App\Support\Labels;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Zmiana statusu rezerwacji — zdarzenie dla jej właściciela (Etap 6, blok G).
 *
 * Kanał private-bookings.{reference}, nazwa "booking.status-changed".
 * Subskrypcję dostaje wyłącznie właściciel (BookingPolicy::listen).
 *
 *     {"reference": "01M2...", "status": "paid", "status_label": "Opłacona",
 *      "occurred_at": "2026-09-15T21:40:12+02:00"}
 *
 * PO CO: ekran "czekamy na potwierdzenie płatności" dowiaduje się
 * o webhooku Stripe'a bez odpytywania API co sekundę.
 *
 * Status przekazuje wywołujący (to, CO SIĘ STAŁO w tej transakcji), a nie
 * odczyt z bazy po COMMIT — między COMMIT a wysyłką rezerwacja mogła już
 * przejść dalej i zdarzenie "paid" zamieniłoby się w drugie "cancelled".
 */
final class BookingStatusChanged implements ShouldBroadcastNow
{
    public function __construct(
        private readonly string $reference,
        private readonly BookingStatus $status,
        private readonly CarbonInterface $occurredAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('bookings.'.$this->reference)];
    }

    public function broadcastAs(): string
    {
        return 'booking.status-changed';
    }

    /**
     * @return array{reference: string, status: string, status_label: string, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => Labels::bookingStatus($this->status),
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }
}
