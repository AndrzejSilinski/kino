<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Jedna kolejność blokowania wierszy seat_locks (Etap 7, blok J).
 *
 * Deadlock powstaje, gdy dwie transakcje blokują TE SAME wiersze w RÓŻNEJ kolejności:
 * A trzyma blokadę 1 i czeka na 2, B trzyma 2 i czeka na 1. Wcześniej checkout
 * i fulfil szły po seat_id, a finish, zwalnianie i sprzątanie po id; w obrębie
 * seansu to dwie różne kolejności (miejsce 9 mogło zostać zablokowane przed 5).
 *
 * Reguła: każde SELECT … FOR UPDATE na seat_locks ma ORDER BY screening_id, seat_id.
 * Wśród niezwolnionych blokad para jest unikalna (indeks seat_locks_active_unique),
 * więc kolejność jest całkowita — także w porcji sprzątania obejmującej wiele seansów.
 *
 * Test patrzy na SQL wysłany do bazy przez każdą ścieżkę, która blokuje te wiersze.
 */
final class SeatLockOrderingTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private array $lockingQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createScreeningWithSeats(2, 5);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }

        DB::listen(function (QueryExecuted $query): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'from "seat_locks"') && str_contains($sql, 'for update')) {
                $this->lockingQueries[] = $sql;
            }
        });
    }

    /** @return list<string> zapytania blokujące seat_locks wykonane w trakcie $action */
    private function lockingQueriesDuring(callable $action): array
    {
        $this->lockingQueries = [];
        $action();

        return $this->lockingQueries;
    }

    private function assertAllInLockOrder(array $queries, string $path): void
    {
        $this->assertNotEmpty($queries, "{$path}: brak SELECT … FOR UPDATE na seat_locks — test nic by nie sprawdził.");

        foreach ($queries as $sql) {
            $this->assertStringContainsString('order by "screening_id" asc, "seat_id" asc', $sql, "{$path}: {$sql}");
        }
    }

    private function pendingBooking(User $user): Booking
    {
        // Miejsca w odwrotnej kolejności: kolejność żądania nie może wpływać na kolejność blokad.
        $this->seatLocks()->lock($this->screening, [$this->seatIds[3], $this->seatIds[1]], self::SESSION, $user->id);

        return app(BookingService::class)->checkout($this->screening, self::SESSION, $user);
    }

    public function test_seat_lock_service_paths_lock_rows_in_one_order(): void
    {
        // Wygasła, niezwolniona blokada na żądanym miejscu: lock() musi ją zwolnić sam.
        SeatLock::factory()->expired()->create(['screening_id' => $this->screening->id, 'seat_id' => $this->seatIds[2]]);
        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => $this->seatLocks()->lock($this->screening, [$this->seatIds[4], $this->seatIds[2]], self::SESSION),
        ), 'lock() zwalnia wygasłe');

        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => $this->seatLocks()->release($this->screening, [$this->seatIds[4]], self::SESSION),
        ), 'release()');

        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => $this->seatLocks()->releaseSession($this->screening, self::SESSION),
        ), 'releaseSession()');

        $other = $this->screening->replicate();
        $other->starts_at = $this->screening->starts_at->addDays(3);
        $other->ends_at = $this->screening->ends_at->addDays(3);
        $other->slot_ends_at = $this->screening->slot_ends_at->addDays(3);
        $other->save();
        SeatLock::factory()->expired()->create(['screening_id' => $other->id, 'seat_id' => $this->seatIds[0]]);
        SeatLock::factory()->expired()->create(['screening_id' => $this->screening->id, 'seat_id' => $this->seatIds[5]]);
        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => $this->seatLocks()->sweepExpired(),
        ), 'sweepExpired() (dwa seanse w porcji)');
    }

    public function test_booking_paths_lock_rows_in_one_order(): void
    {
        $user = User::factory()->create();

        $queries = $this->lockingQueriesDuring(function () use ($user, &$booking): void {
            $booking = $this->pendingBooking($user);
        });
        $this->assertAllInLockOrder($queries, 'checkout()');

        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => app(BookingService::class)->fulfil($booking),
        ), 'fulfil()');

        $second = User::factory()->create();
        $this->seatLocks()->lock($this->screening, [$this->seatIds[8], $this->seatIds[6]], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $second->id);
        $pending = app(BookingService::class)->checkout($this->screening, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $second);
        $this->travelTo(CarbonImmutable::now()->addHour());

        $this->assertAllInLockOrder($this->lockingQueriesDuring(
            fn () => app(BookingService::class)->expire($pending),
        ), 'expire() → finish()');
    }

    public function test_lock_order_scope_is_the_single_definition(): void
    {
        $sql = strtolower(SeatLock::query()->inLockOrder()->toSql());

        $this->assertStringEndsWith('order by "screening_id" asc, "seat_id" asc', $sql);
    }
}
