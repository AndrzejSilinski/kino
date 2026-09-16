<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Exceptions\InvalidScreeningException;
use App\Exceptions\ScreeningConflictException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\SeatLock;
use App\Services\Admin\ScreeningAdminService;
use App\Services\SeatStateRecorder;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Planowanie seansów na PostgreSQL (Etap 7, blok G1): kolizje w trzech warstwach,
 * walidacja, blokada zmian przy sprzedaży, odwołanie. Predykat kolizji bez bazy
 * sprawdza tests/Unit/ScreeningTimelineTest (wymóg 5.2).
 *
 * Konfiguracja buforów ustawiona jawnie (15 min reklam, 20 min sprzątania),
 * "teraz" zamrożone na 1.10.2026 08:00 UTC.
 */
final class ScreeningAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private Cinema $cinema;

    private Hall $hall;

    private Movie $movie;

    private PriceCategory $standard;

    private PriceCategory $vip;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['cinema.screening.ads_minutes' => 15, 'cinema.screening.cleanup_buffer_minutes' => 20]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));

        $this->standard = PriceCategory::factory()->create(['name' => 'Standard T', 'sort_order' => 1]);
        $this->vip = PriceCategory::factory()->create(['name' => 'VIP T', 'sort_order' => 2]);
        $this->cinema = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);
        $this->hall = Hall::factory()->withSeats(1, 3, $this->standard)->for($this->cinema)->create(['projection_types' => ['2d']]);
        $this->hall->seats()->where('seat_number', 3)->update(['price_category_id' => $this->vip->id]);
        $this->movie = Movie::factory()->create(['duration_minutes' => 120]);
    }

    private function service(): ScreeningAdminService
    {
        return app(ScreeningAdminService::class);
    }

    /** @return array{hall_id: int, movie_id: int, date: string, time: string, projection_type: ProjectionType, language_version: LanguageVersion, prices: array<int, int>} */
    private function data(array $override = []): array
    {
        return [
            'hall_id' => $this->hall->id,
            'movie_id' => $this->movie->id,
            'date' => '2026-10-02',
            'time' => '18:00',
            'projection_type' => ProjectionType::TwoD,
            'language_version' => LanguageVersion::Subtitles,
            'prices' => [$this->standard->id => 2500, $this->vip->id => 3900],
            ...$override,
        ];
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (InvalidScreeningException $e) {
            return $e->context()['reason'];
        } catch (StructureChangeBlockedException|ScreeningConflictException $e) {
            return $e->errorCode();
        }

        $this->fail('Oczekiwano wyjątku domenowego.');
    }

    // ─── Dodawanie ───────────────────────────────────────────────────────

    public function test_creates_screening_from_local_time_with_timeline_and_prices(): void
    {
        $generation = app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id));

        $screening = $this->service()->create($this->data());

        // 18:00 w Warszawie (CEST) = 16:00 UTC; +15 min reklam +120 min filmu; +20 min sprzątania.
        $row = DB::table('screenings')->where('id', $screening->id)->first(['starts_at', 'ends_at', 'slot_ends_at', 'status']);
        $this->assertSame(['2026-10-02 16:00:00+00', '2026-10-02 18:15:00+00', '2026-10-02 18:35:00+00', 'scheduled'], [$row->starts_at, $row->ends_at, $row->slot_ends_at, $row->status]);
        $this->assertSame([$this->standard->id => 2500, $this->vip->id => 3900], $screening->prices()->pluck('price', 'price_category_id')->all());
        $this->assertSame($generation + 1, app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id)));
    }

    public function test_conflict_lists_colliding_screening_in_cinema_time(): void
    {
        $this->service()->create($this->data());

        try {
            $this->service()->create($this->data(['time' => '20:34']));
            $this->fail('Oczekiwano SCREENING_CONFLICT.');
        } catch (ScreeningConflictException $e) {
            $this->assertFalse($e->detectedByDatabase);
            $this->assertSame([['id' => Screening::query()->value('id'), 'movie' => $this->movie->title, 'starts_at' => '2026-10-02 18:00', 'slot_ends_at' => '20:35']], $e->conflicts);
            $this->assertStringContainsString('18:00–20:35', $e->getMessage());
        }

        $this->assertSame(1, Screening::query()->count());
    }

    public function test_touching_slots_other_hall_and_cancelled_screening_do_not_collide(): void
    {
        $first = $this->service()->create($this->data());

        $this->service()->create($this->data(['time' => '20:35']));

        $otherHall = Hall::factory()->withSeats(1, 1, $this->standard)->for($this->cinema)->create(['projection_types' => ['2d']]);
        $this->service()->create($this->data(['hall_id' => $otherHall->id, 'prices' => [$this->standard->id => 2500]]));

        // Termin odwołanego seansu jest wolny (17:30 + 155 min = 20:05, przed seansem z 20:35).
        $this->service()->cancel($first);
        $this->service()->create($this->data(['time' => '17:30']));

        $this->assertSame(4, Screening::query()->count());
    }

    public function test_database_constraint_uses_the_same_half_open_rule(): void
    {
        $insert = fn (string $start, string $slotEnd) => DB::table('screenings')->insert([
            'movie_id' => $this->movie->id, 'hall_id' => $this->hall->id,
            'starts_at' => $start, 'ends_at' => $slotEnd, 'slot_ends_at' => $slotEnd,
            'projection_type' => '2d', 'language_version' => 'subtitles', 'status' => 'scheduled',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $insert('2026-10-02 16:00:00+00', '2026-10-02 18:35:00+00');
        $insert('2026-10-02 18:35:00+00', '2026-10-02 20:00:00+00');   // styk: dozwolony

        try {
            DB::transaction(fn () => $insert('2026-10-02 18:34:00+00', '2026-10-02 19:00:00+00'));
            $this->fail('Oczekiwano naruszenia screenings_no_overlap.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
            $this->assertStringContainsString('screenings_no_overlap', $e->getMessage());
        }
    }

    public function test_row_inserted_behind_the_service_is_reported_as_conflict_not_as_500(): void
    {
        // Ktoś omija serwis (np. równoległy seeder) i wstawia kolidujący seans
        // w chwili między sprawdzeniem a naszym INSERT-em.
        Screening::creating(function (Screening $screening): void {
            DB::table('screenings')->insert([
                'movie_id' => $screening->movie_id, 'hall_id' => $screening->hall_id,
                'starts_at' => $screening->starts_at, 'ends_at' => $screening->ends_at, 'slot_ends_at' => $screening->slot_ends_at,
                'projection_type' => '2d', 'language_version' => 'subtitles', 'status' => 'scheduled',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $this->service()->create($this->data());
            $this->fail('Oczekiwano SCREENING_CONFLICT.');
        } catch (ScreeningConflictException $e) {
            $this->assertTrue($e->detectedByDatabase);
        } finally {
            Screening::flushEventListeners();
        }

        $this->assertSame(0, Screening::query()->count(), 'ROLLBACK całej transakcji, także wiersza "z zewnątrz".');
    }

    public function test_validation_rules(): void
    {
        $this->assertSame('in_past', $this->reason(fn () => $this->service()->create($this->data(['date' => '2026-10-01', 'time' => '09:59']))));
        $this->assertSame('projection_unsupported', $this->reason(fn () => $this->service()->create($this->data(['projection_type' => ProjectionType::Imax]))));
        $this->assertSame('prices_missing', $this->reason(fn () => $this->service()->create($this->data(['prices' => [$this->standard->id => 2500]]))));
        $this->assertSame('price_out_of_range', $this->reason(fn () => $this->service()->create($this->data(['prices' => [$this->standard->id => 2500, $this->vip->id => 100_001]]))));
        $this->assertSame('price_out_of_range', $this->reason(fn () => $this->service()->create($this->data(['prices' => [$this->standard->id => -1, $this->vip->id => 3900]]))));
        $this->assertSame('price_category_unknown', $this->reason(fn () => $this->service()->create($this->data(['prices' => [$this->standard->id => 2500, $this->vip->id => 3900, 999_999 => 100]]))));
        $this->assertSame('time_nonexistent', $this->reason(fn () => $this->service()->create($this->data(['date' => '2027-03-28', 'time' => '02:15']))));

        $empty = Hall::factory()->for($this->cinema)->create(['projection_types' => ['2d']]);
        $this->assertSame('hall_without_seats', $this->reason(fn () => $this->service()->create($this->data(['hall_id' => $empty->id]))));

        $this->movie->update(['is_active' => false]);
        $this->assertSame('movie_inactive', $this->reason(fn () => $this->service()->create($this->data())));
        $this->movie->update(['is_active' => true]);

        $this->cinema->update(['is_active' => false]);
        $this->assertSame('hall_inactive', $this->reason(fn () => $this->service()->create($this->data())));

        $this->assertSame(0, Screening::query()->count());
    }

    // ─── Zmiana ──────────────────────────────────────────────────────────

    public function test_update_moves_screening_over_its_own_slot_replaces_prices_and_resyncs_plans(): void
    {
        $screening = $this->service()->create($this->data());
        $version = app(SeatStateRecorder::class)->currentVersion($screening->id);

        $updated = $this->service()->update($screening, $this->data(['time' => '18:30', 'prices' => [$this->standard->id => 2000, $this->vip->id => 3000]]));

        $this->assertSame('2026-10-02 16:30', $updated->starts_at->utc()->format('Y-m-d H:i'), 'Nowy slot nachodzi na stary — własny seans nie jest kolizją.');
        $this->assertSame([$this->standard->id => 2000, $this->vip->id => 3000], $updated->prices()->pluck('price', 'price_category_id')->all());
        $this->assertSame($version + 1, app(SeatStateRecorder::class)->currentVersion($screening->id));
    }

    public function test_update_is_blocked_by_active_lock_or_booking_but_not_by_expired_lock(): void
    {
        $screening = $this->service()->create($this->data());
        $seat = $this->hall->seats()->value('id');

        SeatLock::factory()->expired()->create(['screening_id' => $screening->id, 'seat_id' => $seat]);
        $this->service()->update($screening, $this->data(['time' => '18:15']));

        $lock = SeatLock::factory()->create(['screening_id' => $screening->id, 'seat_id' => $this->hall->seats()->orderByDesc('id')->value('id')]);
        $this->assertSame('SCREENING_HAS_SALES', $this->reason(fn () => $this->service()->update($screening, $this->data(['prices' => [$this->standard->id => 1, $this->vip->id => 1]]))));

        DB::table('seat_locks')->where('id', $lock->id)->update(['released_at' => now()]);   // released_at poza $fillable
        Booking::factory()->paid()->create(['screening_id' => $screening->id]);
        $this->assertSame('SCREENING_HAS_SALES', $this->reason(fn () => $this->service()->update($screening, $this->data(['time' => '19:00']))));

        $this->assertSame(2500, $screening->prices()->where('price_category_id', $this->standard->id)->value('price'));
        $this->assertSame('2026-10-02 16:15', $screening->fresh()->starts_at->utc()->format('Y-m-d H:i'));
    }

    public function test_update_rules_for_hall_and_state(): void
    {
        $screening = $this->service()->create($this->data());

        $foreignHall = Hall::factory()->withSeats(1, 1, $this->standard)->create(['projection_types' => ['2d']]);
        $this->assertSame('hall_other_cinema', $this->reason(fn () => $this->service()->update($screening, $this->data(['hall_id' => $foreignHall->id]))));

        $this->travelTo(CarbonImmutable::parse('2026-10-02 16:01', 'UTC'));
        $this->assertSame('SCREENING_NOT_EDITABLE', $this->reason(fn () => $this->service()->update($screening, $this->data(['date' => '2026-10-03']))));
        $this->assertSame('SCREENING_NOT_EDITABLE', $this->reason(fn () => $this->service()->cancel($screening)));
    }

    // ─── Odwołanie ───────────────────────────────────────────────────────

    public function test_cancel_is_blocked_by_bookings_but_not_by_seat_locks(): void
    {
        $screening = $this->service()->create($this->data());
        SeatLock::factory()->create(['screening_id' => $screening->id, 'seat_id' => $this->hall->seats()->value('id')]);
        $booking = Booking::factory()->create(['screening_id' => $screening->id]);

        $this->assertSame('SCREENING_HAS_BOOKINGS', $this->reason(fn () => $this->service()->cancel($screening)));

        // status nie jest w $fillable — update([...]) pominąłby go po cichu.
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'expired']);
        $version = app(SeatStateRecorder::class)->currentVersion($screening->id);
        $generation = app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id));

        $cancelled = $this->service()->cancel($screening);

        $this->assertSame(ScreeningStatus::Cancelled, $cancelled->status);
        $this->assertSame($version + 1, app(SeatStateRecorder::class)->currentVersion($screening->id));
        $this->assertSame($generation + 1, app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id)));
        $this->assertSame('SCREENING_NOT_EDITABLE', $this->reason(fn () => $this->service()->cancel($screening)));
    }
}
