<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SeatLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

class SeatLockExpiryTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createScreeningWithSeats();
    }

    public function test_po_uplywie_ttl_miejsce_wraca_do_puli(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0]], 'sesja-A');

        // Przesuwamy zegar zamiast czekać 10 minut. Serwis czyta czas przez
        // Carbon::now(), a nie przez bazowe now(), właśnie po to, żeby dało się
        // to zrobić w teście.
        $this->travel((int) config('cinema.seat_lock.ttl') + 1)->seconds();

        $locks = $service->lock($this->screening, [$this->seatIds[0]], 'sesja-B');

        $this->assertCount(1, $locks);
        $this->assertSame('sesja-B', $locks->first()->session_id);
    }

    public function test_wygasla_ale_niezwolniona_blokada_jest_zwalniana_przed_insertem(): void
    {
        // NAJWAŻNIEJSZY TEST TEGO PLIKU.
        // Taki wiersz — TTL minął, released_at nadal NULL — wciąż zajmuje wpis
        // w indeksie seat_locks_active_unique, bo predykat indeksu nie może
        // zawierać now() (PostgreSQL wymaga tam funkcji IMMUTABLE).
        // Gdyby serwis tego nie sprzątał, miejsce byłoby zablokowane na zawsze.
        $stale = SeatLock::factory()->expired()->create([
            'screening_id' => $this->screening->id,
            'seat_id' => $this->seatIds[0],
            'session_id' => 'sesja-A',
        ]);

        $locks = $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], 'sesja-B');

        $this->assertCount(1, $locks);
        $this->assertNotNull($stale->fresh()->released_at, 'Wygasła blokada ma być oznaczona, nie usunięta.');
        $this->assertSame(2, SeatLock::query()->count(), 'Stary wiersz zostaje jako ślad audytowy.');
    }

    public function test_nie_da_sie_zwolnic_cudzej_blokady(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0]], 'sesja-A');

        $released = $service->release($this->screening, [$this->seatIds[0]], 'sesja-B');

        $this->assertSame(0, $released, 'Znajomość identyfikatora miejsca nie wystarcza do zwolnienia cudzej blokady.');
        $this->assertSame(1, SeatLock::query()->whereNull('released_at')->count());
    }

    public function test_zwalnianie_jest_idempotentne(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0]], 'sesja-A');

        $this->assertSame(1, $service->release($this->screening, [$this->seatIds[0]], 'sesja-A'));
        $this->assertSame(0, $service->release($this->screening, [$this->seatIds[0]], 'sesja-A'),
            'Powtórne odkliknięcie miejsca to nie błąd — po prostu nie ma już czego zwalniać.');
    }

    public function test_blokada_wpieta_w_rezerwacje_nie_jest_zwalniana_recznie(): void
    {
        $booking = Booking::factory()->for($this->screening)->create();

        SeatLock::factory()->create([
            'screening_id' => $this->screening->id,
            'seat_id' => $this->seatIds[0],
            'session_id' => 'sesja-A',
            'booking_id' => $booking->id,
        ]);

        $this->assertSame(0, $this->seatLocks()->release($this->screening, [$this->seatIds[0]], 'sesja-A'),
            'Blokada w trakcie płatności należy do procesu płatności, nie do kliknięcia w planie sali.');
    }

    public function test_porzucenie_sesji_zwalnia_wszystkie_jej_miejsca(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], 'sesja-A');
        $service->lock($this->screening, [$this->seatIds[2]], 'sesja-B');

        $this->assertSame(2, $service->releaseSession($this->screening, 'sesja-A'));
        $this->assertSame(1, SeatLock::query()->whereNull('released_at')->count(),
            'Blokada sesji B ma zostać nietknięta.');
    }

    public function test_sweep_zwalnia_wygasle_blokady_porcjami(): void
    {
        foreach ([0, 1, 2] as $i) {
            SeatLock::factory()->expired()->create([
                'screening_id' => $this->screening->id,
                'seat_id' => $this->seatIds[$i],
                'session_id' => "sesja-{$i}",
            ]);
        }

        SeatLock::factory()->create([
            'screening_id' => $this->screening->id,
            'seat_id' => $this->seatIds[3],
            'session_id' => 'sesja-zywa',
        ]);

        $service = $this->seatLocks();

        $this->assertSame(2, $service->sweepExpired(2), 'Porcjowanie: reszta czeka na kolejny przebieg.');
        $this->assertSame(1, $service->sweepExpired(2));
        $this->assertSame(0, $service->sweepExpired(2));

        $this->assertSame(1, SeatLock::query()->whereNull('released_at')->count(),
            'Żywa blokada nie może zostać ruszona przez scheduler.');
    }
}
