<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ScreeningPrice;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Zwolnienie miejsc zwraca aktualny koszyk (Etap 8, blok F).
 *
 * SPA nie zmienia planu sali optymistycznie: po odkliknięciu czeka na odpowiedź serwera
 * i z niej bierze listę miejsc, sumę i czas wygaśnięcia. Koszyk w odpowiedzi DELETE oszczędza
 * drugie żądanie z limitu 30/min na sesję zakupową.
 */
final class SeatLockReleaseResponseTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createScreeningWithSeats(rows: 1, cols: 4);

        $categories = Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique();
        foreach ($categories as $categoryId) {
            ScreeningPrice::factory()->create([
                'screening_id' => $this->screening->id,
                'price_category_id' => $categoryId,
                'price' => 2500,
            ]);
        }
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/screenings/'.$this->screening->id.'/seat-locks'.$suffix;
    }

    public function test_releasing_one_seat_returns_the_remaining_cart(): void
    {
        $this->withHeader('X-Session-Id', self::SESSION)
            ->postJson($this->url(), ['seat_ids' => [$this->seatIds[0], $this->seatIds[1]]])
            ->assertCreated();

        $this->withHeader('X-Session-Id', self::SESSION)
            ->deleteJson($this->url('/'.$this->seatIds[0]))
            ->assertOk()
            ->assertHeader('X-Session-Id', self::SESSION)
            ->assertJsonPath('data.seats_count', 1)
            ->assertJsonPath('data.seats.0.seat_id', $this->seatIds[1])
            ->assertJsonPath('data.total.amount', 2500)
            ->assertJsonPath('meta.screening_id', $this->screening->id);
    }

    public function test_releasing_the_whole_cart_returns_an_empty_cart_without_timer(): void
    {
        $this->withHeader('X-Session-Id', self::SESSION)
            ->postJson($this->url(), ['seat_ids' => [$this->seatIds[2]]])
            ->assertCreated();

        $this->withHeader('X-Session-Id', self::SESSION)
            ->deleteJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.seats_count', 0)
            ->assertJsonPath('data.total.amount', 0)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.expires_in_seconds', null);

        $this->assertDatabaseMissing('seat_locks', ['seat_id' => $this->seatIds[2], 'released_at' => null]);
    }
}
