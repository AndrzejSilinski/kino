<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\Labels;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Wpis live feedu sprzedaży dla panelu (Etap 6, blok G; wymóg 1.3 i 2.3).
 *
 * JEDNO zdarzenie, DWA kanały w jednym żądaniu do Reverba:
 *   private-sales                  — cała sieć, tylko administrator,
 *   private-cinemas.{id}.sales     — jedno kino, administrator i obsługa tego kina.
 * Uprawnienia: CinemaPolicy::viewAnySales / viewSales (blok D).
 *
 * BEZ DANYCH OSOBOWYCH: żadnego e-maila, imienia ani user_id. Panel widzi
 * CO sprzedano (seans, liczba miejsc, kwota), a nie KOMU. Szczegóły klienta
 * admin zobaczy w liście rezerwacji (Etap 7), za autoryzacją HTTP.
 *
 * Wymaga relacji screening.movie i screening.hall.cinema oraz licznika
 * seat_locks_count — ładuje je RealtimeNotifier jednym zapytaniem.
 */
final class SalesActivity implements ShouldBroadcastNow
{
    public function __construct(
        private readonly Booking $booking,
        private readonly BookingStatus $status,
        private readonly CarbonInterface $occurredAt,
    ) {}

    /**
     * Typ wpisu wynika z przejścia, które właśnie się wydarzyło.
     * match bez default — jak w Labels: nowy status bez typu to głośny błąd.
     */
    public static function typeFor(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Pending => 'booking.created',
            BookingStatus::Paid => 'booking.paid',
            BookingStatus::Cancelled => 'booking.cancelled',
            BookingStatus::Expired => 'booking.expired',
            BookingStatus::Refunded => 'booking.refunded',
        };
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sales'),
            new PrivateChannel('cinemas.'.$this->booking->screening->hall->cinema_id.'.sales'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sales.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $screening = $this->booking->screening;
        $cinema = $screening->hall->cinema;

        return [
            'type' => self::typeFor($this->status),
            'reference' => $this->booking->reference,
            'status' => $this->status->value,
            'status_label' => Labels::bookingStatus($this->status),
            'cinema' => [
                'id' => $cinema->id,
                'name' => $cinema->name,
            ],
            'screening' => [
                'id' => $screening->id,
                // Godzina seansu w strefie kina — tak samo jak przy walidacji biletu.
                'starts_at' => $screening->starts_at->copy()->setTimezone($cinema->timezone)->toIso8601String(),
                'movie_title' => $screening->movie->title,
                'hall_name' => $screening->hall->name,
            ],
            'seats_count' => (int) $this->booking->seat_locks_count,
            'total' => Money::minor((int) $this->booking->total_amount, $this->booking->currency)->toArray(),
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }
}
