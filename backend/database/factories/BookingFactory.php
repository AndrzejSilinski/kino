<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        // Pola 'reference' NIE ustawiamy — nadaje je metoda booted() modelu
        // (ULID). Gdyby factory je podawała, ominęlibyśmy prawdziwy mechanizm
        // aplikacji i test przestałby go sprawdzać.
        return [
            'user_id' => User::factory(),
            'screening_id' => Screening::factory(),
            'status' => BookingStatus::from('pending'),
            'total_amount' => 5000,
            'currency' => config('cinema.booking.currency', 'PLN'),
            'stripe_payment_intent_id' => null,
            'expires_at' => now()->addSeconds((int) config('cinema.seat_lock.ttl', 600)),
            'paid_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::from('paid'),
            'paid_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::from('expired'),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::from('cancelled'),
            'cancelled_at' => now(),
            'cancellation_reason' => 'Anulowane w teście.',
        ]);
    }
}
