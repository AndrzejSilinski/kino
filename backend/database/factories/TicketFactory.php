<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Seat;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            // screening_id jest ZDENORMALIZOWANE (decyzja projektowa nr 6) i musi
            // zgadzać się z rezerwacją. Domknięcie dostaje atrybuty rozwinięte
            // do tego momentu, więc 'booking_id' jest tu już konkretnym id.
            // Bez tego indeks tickets_active_seat_unique pilnowałby złego seansu.
            'screening_id' => fn (array $attributes): int => Booking::query()
                ->whereKey($attributes['booking_id'])
                ->value('screening_id'),
            'seat_id' => Seat::factory(),
            'price' => 2500,
            'status' => TicketStatus::from('valid'),
            'validated_at' => null,
            'validated_by_user_id' => null,
        ];
        // 'code' (UUID v4) nadaje booted() modelu — tak jak przy reference w Booking.
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::from('used'),
            'validated_at' => now(),
        ]);
    }

    /**
     * Bilet anulowany. Ważny dla testów: indeks tickets_active_seat_unique ma
     * predykat "WHERE status <> 'cancelled'", więc anulowany bilet ZWALNIA
     * miejsce do ponownej sprzedaży.
     */
    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => TicketStatus::from('cancelled')]);
    }
}
