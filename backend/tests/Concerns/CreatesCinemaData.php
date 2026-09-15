<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Hall;
use App\Models\Screening;
use App\Services\SeatLockService;

/**
 * Minimalny, powtarzalny świat dla testów blokowania miejsc:
 * jedna sala z siatką foteli i jeden seans, który tę salę zajmuje.
 *
 * Świadomie NIE korzystamy z seederów — one budują realistyczne dane
 * demonstracyjne (3 kina, ~1800 miejsc), a test ma tworzyć dokładnie tyle,
 * ile sprawdza. Mniej wierszy to szybszy i czytelniejszy test.
 */
trait CreatesCinemaData
{
    protected Hall $hall;

    protected Screening $screening;

    /** @var list<int> identyfikatory miejsc posortowane rosnąco */
    protected array $seatIds;

    protected function createScreeningWithSeats(int $rows = 2, int $cols = 5): void
    {
        $this->hall = Hall::factory()->withSeats($rows, $cols)->create();
        $this->screening = Screening::factory()->for($this->hall)->create();
        $this->seatIds = array_values(
            $this->hall->seats()->orderBy('id')->pluck('id')->all()
        );
    }

    /**
     * Serwis bierzemy z kontenera, a nie przez new, żeby test przechodził
     * tą samą ścieżką co kontroler w Etapie 3 (wstrzykiwanie zależności).
     */
    protected function seatLocks(): SeatLockService
    {
        return app(SeatLockService::class);
    }
}
