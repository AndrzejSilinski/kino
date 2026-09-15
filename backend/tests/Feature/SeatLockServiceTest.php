<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\SeatLock;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

class SeatLockServiceTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createScreeningWithSeats();
    }

    public function test_blokuje_wskazane_miejsca(): void
    {
        $locks = $this->seatLocks()->lock($this->screening, [$this->seatIds[1], $this->seatIds[0]], 'sesja-A');

        $this->assertCount(2, $locks);
        $this->assertSame(2, SeatLock::query()->whereNull('released_at')->count());
        $this->assertSame(
            [$this->seatIds[0], $this->seatIds[1]],
            $locks->pluck('seat_id')->all(),
            'Wejście podane w odwrotnej kolejności ma wrócić posortowane — ta sama kolejność chroni przed deadlockiem.'
        );
    }

    public function test_podwojne_kliknicie_nie_tworzy_drugiej_blokady(): void
    {
        $service = $this->seatLocks();
        $seats = [$this->seatIds[0]];

        $first = $service->lock($this->screening, $seats, 'sesja-A');
        $second = $service->lock($this->screening, $seats, 'sesja-A');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(1, SeatLock::query()->count(), 'Retry żądania nie może utworzyć drugiego wiersza.');
        $this->assertTrue($first->first()->is($second->first()));
    }

    public function test_ponowne_zadanie_nie_przedluza_czasu_blokady(): void
    {
        $service = $this->seatLocks();
        $seats = [$this->seatIds[0]];

        $first = $service->lock($this->screening, $seats, 'sesja-A')->first();

        $this->travel(2)->minutes();

        $second = $service->lock($this->screening, $seats, 'sesja-A')->first();

        $this->assertEquals(
            $first->expires_at,
            $second->expires_at,
            'Gdyby retry odnawiał TTL, klient trzymałby fotel bez końca, pingując endpoint co minutę.'
        );
    }

    public function test_cudza_blokada_konczy_sie_konfliktem_z_lista_miejsc(): void
    {
        $service = $this->seatLocks();
        $seats = [$this->seatIds[0]];

        $service->lock($this->screening, $seats, 'sesja-A');

        try {
            $service->lock($this->screening, $seats, 'sesja-B');
            $this->fail('Sesja B nie miała prawa dostać zajętego miejsca.');
        } catch (SeatsUnavailableException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame('SEATS_UNAVAILABLE', $e->errorCode());
            $this->assertSame($seats, $e->seatIds, 'Frontend musi wiedzieć, KTÓRE miejsca odświeżyć.');
            $this->assertSame(['A1'], $e->seatLabels, 'Użytkownik widzi "A1", nie wewnętrzny identyfikator.');
        }
    }

    public function test_zajete_jedno_miejsce_uniewaznia_cala_probe(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[1]], 'sesja-B');

        $this->expectException(SeatsUnavailableException::class);

        try {
            $service->lock($this->screening, [$this->seatIds[0], $this->seatIds[1], $this->seatIds[2]], 'sesja-A');
        } finally {
            $this->assertSame(0, SeatLock::query()->where('session_id', 'sesja-A')->count(),
                'Blokowanie jest all-or-nothing — rodzina nie może dostać 2 miejsc z 3 i usiąść osobno.');
        }
    }

    public function test_sprzedane_miejsce_jest_niedostepne_mimo_braku_blokady(): void
    {
        // Bilet powstaje z blokady, a blokada jest potem zwalniana. Indeks
        // seat_locks_active_unique już wtedy nie chroni miejsca — dlatego serwis
        // sprawdza tabelę tickets osobno, wewnątrz tej samej transakcji.
        $booking = Booking::factory()->for($this->screening)->create();
        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $this->seatIds[0]]);

        $this->expectException(SeatsUnavailableException::class);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], 'sesja-A');
    }

    public function test_anulowany_bilet_zwalnia_miejsce_do_ponownej_sprzedazy(): void
    {
        // Predykat indeksu tickets_active_seat_unique brzmi "WHERE status <> 'cancelled'",
        // więc anulowany bilet nie rezerwuje już fotela. Serwis musi to odwzorować.
        $booking = Booking::factory()->for($this->screening)->create();
        Ticket::factory()->cancelled()->create(['booking_id' => $booking->id, 'seat_id' => $this->seatIds[0]]);

        $locks = $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], 'sesja-A');

        $this->assertCount(1, $locks);
    }
}
