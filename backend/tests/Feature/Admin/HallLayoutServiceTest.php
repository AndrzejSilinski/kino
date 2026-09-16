<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ScreeningStatus;
use App\Events\SeatsResync;
use App\Exceptions\InvalidHallLayoutException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Booking;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\SeatLock;
use App\Models\Seat;
use App\Models\Ticket;
use App\Services\Admin\HallLayoutService;
use App\Services\SeatLockService;
use App\Services\SeatStateRecorder;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Zapis układu sali (Etap 7, blok E): tryb pełny, walidacja, tryb ograniczony
 * i strażnicy sprzedaży.
 */
final class HallLayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceCategory $standard;

    private PriceCategory $vip;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->standard = PriceCategory::factory()->create(['name' => 'Standard testowy', 'sort_order' => 1]);
        $this->vip = PriceCategory::factory()->create(['name' => 'VIP testowy', 'sort_order' => 2]);
    }

    private function layouts(): HallLayoutService
    {
        return app(HallLayoutService::class);
    }

    /** @return array{id: ?int, x: int, y: int, type: string, category_id: int, active: bool} */
    private function seat(int $x, int $y, string $type = 'standard', ?int $category = null, bool $active = true, ?int $id = null): array
    {
        return ['id' => $id, 'x' => $x, 'y' => $y, 'type' => $type, 'category_id' => $category ?? $this->standard->id, 'active' => $active];
    }

    /** Sala 1 x 3 (A1..A3) z nadchodzącym seansem i cennikiem kategorii standard. */
    private function hallWithUpcomingScreening(): array
    {
        $hall = Hall::factory()->withSeats(1, 3, $this->standard)->create();
        $screening = Screening::factory()->for($hall)->create();
        ScreeningPrice::factory()->create(['screening_id' => $screening->id, 'price_category_id' => $this->standard->id, 'price' => 2500]);

        return [$hall, $screening];
    }

    /** @param list<array<string, mixed>> $seats */
    private function withChange(array $seats, string $label, array $change): array
    {
        return array_map(fn (array $seat): array => $seat['label'] === $label ? [...$seat, ...$change] : $seat, $seats);
    }

    // ─── Tryb pełny ──────────────────────────────────────────────────────

    public function test_full_mode_replaces_layout_and_numbers_rows_without_gaps(): void
    {
        $hall = Hall::factory()->withSeats(2, 3, $this->standard)->create();
        $oldIds = Seat::query()->where('hall_id', $hall->id)->pluck('id')->all();

        $this->assertSame(HallLayoutService::MODE_FULL, $this->layouts()->mode($hall));

        $this->layouts()->replace($hall, [
            $this->seat(4, 3, 'accessible'),
            $this->seat(1, 1),
            $this->seat(2, 1, category: $this->vip->id),
            $this->seat(1, 3, 'double'),
        ]);

        $labels = collect($this->layouts()->currentSeats($hall))->mapWithKeys(fn (array $s): array => [$s['x'].':'.$s['y'] => $s['label']])->all();

        // Rząd siatki 2 jest pusty, więc rząd 3 dostaje literę B, a nie C.
        $this->assertSame(['1:1' => 'A1', '2:1' => 'A2', '1:3' => 'B1', '4:3' => 'B2'], $labels);
        $this->assertSame(0, Seat::query()->whereIn('id', $oldIds)->count(), 'Stare miejsca bez historii zostały zastąpione.');

        $hall->refresh();
        $this->assertSame([3, 4], [$hall->grid_rows, $hall->grid_cols]);
        $this->assertSame(1, app(CatalogCache::class)->generation(CatalogCache::cinema($hall->cinema_id)));
    }

    public function test_invalid_layout_reports_all_problems_and_changes_nothing(): void
    {
        $hall = Hall::factory()->withSeats(1, 2, $this->standard)->create();
        $before = $this->layouts()->currentSeats($hall);

        try {
            $this->layouts()->replace($hall, [
                $this->seat(1, 1, active: false),
                $this->seat(1, 1, active: false),
                $this->seat(3, 2, 'double', active: false),
                $this->seat(4, 2, active: false),
                $this->seat(6, 2, category: 999999, active: false),
                ['x' => 'abc', 'y' => 1, 'type' => 'standard', 'category_id' => $this->standard->id, 'active' => true],
            ]);
            $this->fail('Oczekiwano InvalidHallLayoutException.');
        } catch (InvalidHallLayoutException $e) {
            $this->assertContains('Miejsca nachodzą na siebie w rzędzie siatki 1, kratka 1.', $e->problems);
            $this->assertContains('Miejsca nachodzą na siebie w rzędzie siatki 2, kratka 4.', $e->problems);
            $this->assertContains('Nieznana kategoria cenowa (id 999999).', $e->problems);
            $this->assertContains('Układ musi mieć co najmniej jedno aktywne miejsce.', $e->problems);
            $this->assertContains('Miejsce nr 6: brak albo zła wartość pozycji, typu, kategorii lub dostępności.', $e->problems);
            $this->assertSame(422, $e->status());
        }

        $this->assertSame($before, $this->layouts()->currentSeats($hall));
    }

    // ─── Tryb ograniczony ────────────────────────────────────────────────

    public function test_upcoming_screening_or_sales_history_switches_to_restricted_mode(): void
    {
        [$withScreening] = $this->hallWithUpcomingScreening();
        $this->assertSame(HallLayoutService::MODE_RESTRICTED, $this->layouts()->mode($withScreening));

        // Tylko historia: bilet na zakończonym seansie, żadnych nadchodzących.
        $withHistory = Hall::factory()->withSeats(1, 2, $this->standard)->create();
        $past = Screening::factory()->for($withHistory)->finished()->create();
        $booking = Booking::factory()->paid()->create(['screening_id' => $past->id]);
        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $withHistory->seats()->value('id')]);

        $this->assertSame(HallLayoutService::MODE_RESTRICTED, $this->layouts()->mode($withHistory));

        // Sama zwolniona blokada (porzucony koszyk) też jest historią: DELETE miejsc
        // skasowałby ją albo zatrzymał się na kluczu obcym.
        $withLock = Hall::factory()->withSeats(1, 2, $this->standard)->create();
        $pastForLock = Screening::factory()->for($withLock)->finished()->create();
        SeatLock::factory()->released()->create(['screening_id' => $pastForLock->id, 'seat_id' => $withLock->seats()->value('id')]);

        $this->assertSame(HallLayoutService::MODE_RESTRICTED, $this->layouts()->mode($withLock));
    }

    public function test_restricted_mode_rejects_adding_or_moving_seats(): void
    {
        [$hall] = $this->hallWithUpcomingScreening();
        $seats = $this->layouts()->currentSeats($hall);

        foreach ([
            [...$seats, $this->seat(5, 1)],
            $this->withChange($seats, 'A2', ['x' => 7]),
            array_slice($seats, 1),
        ] as $attempt) {
            try {
                $this->layouts()->replace($hall, $attempt);
                $this->fail('Oczekiwano HALL_LAYOUT_RESTRICTED.');
            } catch (StructureChangeBlockedException $e) {
                $this->assertSame('HALL_LAYOUT_RESTRICTED', $e->errorCode());
            }
        }

        $this->assertSame(3, Seat::query()->where('hall_id', $hall->id)->count());
    }

    public function test_restricted_mode_updates_category_type_and_availability_in_place_and_resyncs_plans(): void
    {
        Event::fake([SeatsResync::class]);
        [$hall, $screening] = $this->hallWithUpcomingScreening();
        ScreeningPrice::factory()->create(['screening_id' => $screening->id, 'price_category_id' => $this->vip->id, 'price' => 3900]);
        $ids = collect($this->layouts()->currentSeats($hall))->pluck('id', 'label')->all();

        $seats = $this->layouts()->currentSeats($hall);
        $seats = $this->withChange($seats, 'A1', ['category_id' => $this->vip->id]);
        $seats = $this->withChange($seats, 'A2', ['type' => 'accessible']);
        $seats = $this->withChange($seats, 'A3', ['active' => false]);

        $this->layouts()->replace($hall, $seats);

        $after = collect($this->layouts()->currentSeats($hall))->keyBy('label');
        $this->assertSame($ids, $after->pluck('id', 'label')->all(), 'Identyfikatory i etykiety bez zmian.');
        $this->assertSame($this->vip->id, $after['A1']['category_id']);
        $this->assertSame('accessible', $after['A2']['type']);
        $this->assertFalse($after['A3']['active']);

        $this->assertSame(1, app(SeatStateRecorder::class)->currentVersion($screening->id));
        Event::assertDispatched(SeatsResync::class);
    }

    public function test_category_change_of_held_seat_is_blocked_and_rolled_back(): void
    {
        [$hall, $screening] = $this->hallWithUpcomingScreening();
        ScreeningPrice::factory()->create(['screening_id' => $screening->id, 'price_category_id' => $this->vip->id, 'price' => 3900]);
        $seats = $this->layouts()->currentSeats($hall);
        $a2 = collect($seats)->firstWhere('label', 'A2')['id'];
        app(SeatLockService::class)->lock($screening, [$a2], str_repeat('b', 32));

        $changed = $this->withChange($this->withChange($seats, 'A2', ['category_id' => $this->vip->id]), 'A3', ['category_id' => $this->vip->id]);

        try {
            $this->layouts()->replace($hall, $changed);
            $this->fail('Oczekiwano HALL_LAYOUT_SEATS_HELD.');
        } catch (StructureChangeBlockedException $e) {
            $this->assertSame('HALL_LAYOUT_SEATS_HELD', $e->errorCode());
            $this->assertSame(['A2'], $e->context()['seats']);
        }

        $this->assertSame($seats, $this->layouts()->currentSeats($hall), 'Całość wycofana, także zmiana A3.');
        $this->assertSame(0, app(CatalogCache::class)->generation(CatalogCache::cinema($hall->cinema_id)));
    }

    public function test_sold_seat_cannot_be_deactivated(): void
    {
        [$hall, $screening] = $this->hallWithUpcomingScreening();
        $seats = $this->layouts()->currentSeats($hall);
        $booking = Booking::factory()->paid()->create(['screening_id' => $screening->id]);
        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => collect($seats)->firstWhere('label', 'A1')['id']]);

        $this->expectException(StructureChangeBlockedException::class);
        $this->expectExceptionMessage('Nie można wyłączyć miejsc sprzedanych na nadchodzące seanse: A1.');

        $this->layouts()->replace($hall, $this->withChange($seats, 'A1', ['active' => false]));
    }

    public function test_category_without_price_on_upcoming_screening_is_blocked(): void
    {
        [$hall] = $this->hallWithUpcomingScreening();
        $seats = $this->layouts()->currentSeats($hall);

        try {
            $this->layouts()->replace($hall, $this->withChange($seats, 'A1', ['category_id' => $this->vip->id]));
            $this->fail('Oczekiwano HALL_LAYOUT_PRICES_MISSING.');
        } catch (StructureChangeBlockedException $e) {
            $this->assertSame('HALL_LAYOUT_PRICES_MISSING', $e->errorCode());
            $this->assertSame(['categories' => ['VIP testowy'], 'screenings' => 1], $e->context());
        }
    }

    public function test_cancelled_screening_does_not_restrict_layout(): void
    {
        [$hall, $screening] = $this->hallWithUpcomingScreening();
        $screening->update(['status' => ScreeningStatus::Cancelled]);

        $this->assertSame(HallLayoutService::MODE_FULL, $this->layouts()->mode($hall));
    }
}
