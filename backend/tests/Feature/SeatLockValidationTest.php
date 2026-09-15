<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidSeatSelectionException;
use App\Exceptions\ScreeningNotBookableException;
use App\Exceptions\SeatLockLimitExceededException;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

class SeatLockValidationTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createScreeningWithSeats();
    }

    public function test_pusta_lista_miejsc_jest_odrzucana(): void
    {
        $this->assertRejectedWithCode(fn () => $this->seatLocks()->lock($this->screening, [], 'sesja-A'), 'EMPTY_SEAT_SELECTION');
    }

    public function test_duplikaty_w_zadaniu_sa_odrzucane(): void
    {
        $this->assertRejectedWithCode(
            fn () => $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[0]], 'sesja-A'),
            'DUPLICATE_SEATS'
        );
    }

    public function test_miejsce_z_innej_sali_jest_odrzucane(): void
    {
        // Tabela seat_locks nie ma klucza obcego wiążącego salę z seansem —
        // klucz prowadzi do seats. Bez tej kontroli dałoby się zablokować
        // fotel z zupełnie innego kina.
        $otherHall = Hall::factory()->withSeats(1, 2)->create();
        $foreignSeatId = (int) $otherHall->seats()->value('id');

        $this->assertRejectedWithCode(
            fn () => $this->seatLocks()->lock($this->screening, [$foreignSeatId], 'sesja-A'),
            'SEATS_NOT_IN_HALL'
        );
    }

    public function test_nieistniejace_miejsce_jest_odrzucane(): void
    {
        $this->assertRejectedWithCode(
            fn () => $this->seatLocks()->lock($this->screening, [999999], 'sesja-A'),
            'SEATS_NOT_IN_HALL'
        );
    }

    public function test_miejsce_wylaczone_ze_sprzedazy_jest_odrzucane(): void
    {
        Seat::query()->whereKey($this->seatIds[0])->update(['is_active' => false]);

        $this->assertRejectedWithCode(
            fn () => $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], 'sesja-A'),
            'SEATS_INACTIVE'
        );
    }

    public function test_odwolany_seans_nie_przyjmuje_blokad(): void
    {
        $cancelled = Screening::factory()->for($this->hall)->cancelled()->create();

        try {
            $this->seatLocks()->lock($cancelled, [$this->seatIds[0]], 'sesja-A');
            $this->fail('Odwołany seans nie może przyjmować rezerwacji.');
        } catch (ScreeningNotBookableException $e) {
            $this->assertSame(409, $e->status(), 'To konflikt stanu zasobu, nie błąd danych wejściowych.');
            $this->assertSame('SCREENING_NOT_BOOKABLE', $e->errorCode());
        }
    }

    public function test_rozpoczety_seans_nie_przyjmuje_blokad(): void
    {
        $started = Screening::factory()->for($this->hall)->started()->create();

        $this->expectException(ScreeningNotBookableException::class);

        $this->seatLocks()->lock($started, [$this->seatIds[0]], 'sesja-A');
    }

    public function test_limit_miejsc_liczy_sie_dla_calej_sesji_a_nie_dla_zadania(): void
    {
        // Gdyby limit dotyczył pojedynczego żądania, wystarczyłoby wysłać
        // dziesięć żądań po jednym miejscu i zablokować całą salę.
        config()->set('cinema.seat_lock.max_seats_per_session', 3);

        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], 'sesja-A');

        try {
            $service->lock($this->screening, [$this->seatIds[2], $this->seatIds[3]], 'sesja-A');
            $this->fail('Przekroczony limit miejsc powinien zostać odrzucony.');
        } catch (SeatLockLimitExceededException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame(3, $e->limit);
            $this->assertSame(4, $e->requested);
        }

        $this->assertSame(2, SeatLock::query()->whereNull('released_at')->count(),
            'Odrzucone żądanie nie może zostawić po sobie częściowych blokad.');
    }

    private function assertRejectedWithCode(callable $action, string $expectedCode): void
    {
        try {
            $action();
            $this->fail("Oczekiwano odrzucenia z kodem {$expectedCode}.");
        } catch (InvalidSeatSelectionException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame($expectedCode, $e->errorCode());
        }
    }
}
