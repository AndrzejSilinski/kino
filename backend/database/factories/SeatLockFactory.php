<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SeatLock>
 */
class SeatLockFactory extends Factory
{
    protected $model = SeatLock::class;

    /**
     * UWAGA: domyślnie tworzy własny seans i własne miejsce, które NIE należy
     * do sali tego seansu. Baza na to pozwala (nie ma klucza obcego wiążącego
     * salę z seansem — dlatego SeatLockService sprawdza to w PHP).
     * W testach blokowania zawsze podawaj screening_id i seat_id jawnie.
     */
    public function definition(): array
    {
        return [
            'screening_id' => Screening::factory(),
            'seat_id' => Seat::factory(),
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'booking_id' => null,
            'expires_at' => now()->addSeconds((int) config('cinema.seat_lock.ttl', 600)),
            'released_at' => null,
        ];
    }

    /**
     * Blokada wygasła, ale NIEZWOLNIONA — najważniejszy stan w całym etapie.
     * Taki wiersz nadal zajmuje miejsce w indeksie seat_locks_active_unique,
     * bo predykat indeksu nie może zawierać now(). To ten przypadek musi
     * obsłużyć SeatLockService, zwalniając go we własnej transakcji.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinutes(5)]);
    }

    /** Blokada porządnie zwolniona — nie zajmuje już indeksu. */
    public function released(): static
    {
        return $this->state(fn (): array => ['released_at' => now()]);
    }

    public function forSession(string $sessionId): static
    {
        return $this->state(fn (): array => ['session_id' => $sessionId]);
    }
}
