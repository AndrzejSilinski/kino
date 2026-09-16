<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Exceptions\RepertoireCopyBlockedException;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Services\Admin\RepertoireCopyService;
use App\Services\Admin\ScreeningAdminService;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kopiowanie repertuaru z dnia na dzień (Etap 7, blok H): godziny lokalne, cenniki,
 * wszystko albo nic, idempotencja, podgląd bez zmian.
 *
 * "Teraz" = 20.10.2026 08:00 UTC. Zmiana czasu w Warszawie 25.10 (CEST -> CET),
 * więc kopia z 24.10 na 26.10 przesuwa moment w UTC o godzinę.
 */
final class RepertoireCopyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Cinema $cinema;

    private Hall $hallA;

    private Hall $hallB;

    private Movie $movie;

    private PriceCategory $standard;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['cinema.screening.ads_minutes' => 15, 'cinema.screening.cleanup_buffer_minutes' => 20]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'UTC'));

        $this->standard = PriceCategory::factory()->create(['name' => 'Standard T']);
        $this->cinema = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);
        $this->hallA = Hall::factory()->withSeats(1, 2, $this->standard)->for($this->cinema)->create(['name' => 'Sala A', 'projection_types' => ['2d', '3d']]);
        $this->hallB = Hall::factory()->withSeats(1, 2, $this->standard)->for($this->cinema)->create(['name' => 'Sala B', 'projection_types' => ['2d']]);
        $this->movie = Movie::factory()->create(['title' => 'Film T', 'duration_minutes' => 120]);
    }

    private function add(Hall $hall, string $date, string $time, int $price = 2500): Screening
    {
        return app(ScreeningAdminService::class)->create([
            'hall_id' => $hall->id, 'movie_id' => $this->movie->id, 'date' => $date, 'time' => $time,
            'projection_type' => ProjectionType::TwoD, 'language_version' => LanguageVersion::Subtitles,
            'prices' => [$this->standard->id => $price],
        ]);
    }

    private function service(): RepertoireCopyService
    {
        return app(RepertoireCopyService::class);
    }

    /** @return list<string> "Sala H:i UTC" dla dnia docelowego */
    private function day(string $date): array
    {
        return Screening::query()->with('hall')
            ->where('status', '!=', ScreeningStatus::Cancelled)
            ->whereBetween('starts_at', [CarbonImmutable::parse($date.' 00:00', 'Europe/Warsaw')->utc(), CarbonImmutable::parse($date.' 23:59', 'Europe/Warsaw')->utc()])
            ->orderBy('hall_id')->orderBy('starts_at')->get()
            ->map(fn (Screening $s): string => $s->hall->name.' '.$s->starts_at->setTimezone('Europe/Warsaw')->format('H:i').' = '.$s->starts_at->utc()->format('H:i').' UTC')
            ->all();
    }

    public function test_copies_local_times_and_prices_across_clock_change(): void
    {
        $this->add($this->hallA, '2026-10-24', '18:00', 2750);
        $this->add($this->hallB, '2026-10-24', '20:30');
        $cancelled = $this->add($this->hallB, '2026-10-24', '12:00');
        app(ScreeningAdminService::class)->cancel($cancelled);
        $generation = app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id));

        $report = $this->service()->copy($this->cinema, '2026-10-24', '2026-10-26');

        $this->assertSame(2, $report['created']);
        $this->assertSame(['Sala A 18:00 = 17:00 UTC', 'Sala B 20:30 = 19:30 UTC'], $this->day('2026-10-26'), 'Ta sama godzina lokalna, po zmianie czasu inny moment w UTC; odwołany seans pominięty.');
        $this->assertSame(['Sala A 18:00 = 16:00 UTC', 'Sala B 20:30 = 18:30 UTC'], $this->day('2026-10-24'));
        $copy = Screening::query()->where('hall_id', $this->hallA->id)->latest('starts_at')->first();
        $this->assertSame([$this->standard->id => 2750], $copy->prices()->pluck('price', 'price_category_id')->all());
        $this->assertSame('2026-10-26 19:35', $copy->slot_ends_at->utc()->format('Y-m-d H:i'), 'Slot policzony od nowa: 17:00 + 155 min.');
        $this->assertGreaterThan($generation, app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id)));
    }

    public function test_one_problem_blocks_the_whole_copy_and_report_lists_it(): void
    {
        $this->add($this->hallA, '2026-10-22', '18:00');
        $this->add($this->hallB, '2026-10-22', '18:00');
        $this->add($this->hallB, '2026-10-23', '19:00');   // koliduje z kopią 18:00 w sali B

        try {
            $this->service()->copy($this->cinema, '2026-10-22', '2026-10-23');
            $this->fail('Oczekiwano REPERTOIRE_COPY_BLOCKED.');
        } catch (RepertoireCopyBlockedException $e) {
            $this->assertSame('REPERTOIRE_COPY_BLOCKED', $e->errorCode());
            $this->assertSame(['create', 'problem'], array_column($e->items, 'status'));
            $this->assertSame('Sala B', $e->items[1]['hall']);
            $this->assertStringContainsString('2026-10-23 19:00–21:35', (string) $e->items[1]['message']);
        }

        $this->assertSame(['Sala B 19:00 = 17:00 UTC'], $this->day('2026-10-23'), 'Sala A też nie dostała seansu — wszystko albo nic.');
    }

    public function test_second_copy_reports_existing_and_creates_nothing(): void
    {
        $this->add($this->hallA, '2026-10-22', '18:00');
        $this->add($this->hallA, '2026-10-22', '21:00');

        $this->assertSame(2, $this->service()->copy($this->cinema, '2026-10-22', '2026-10-23')['created']);
        $again = $this->service()->copy($this->cinema, '2026-10-22', '2026-10-23');

        $this->assertSame(['created' => 0, 'existing' => 2], ['created' => $again['created'], 'existing' => $again['existing']]);
        $this->assertSame(['exists', 'exists'], array_column($again['items'], 'status'));
        $this->assertCount(2, $this->day('2026-10-23'));
    }

    public function test_preview_shows_the_same_report_and_changes_nothing(): void
    {
        $this->add($this->hallA, '2026-10-22', '18:00');
        $ids = Screening::query()->pluck('id')->all();
        $generation = app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id));

        $preview = $this->service()->preview($this->cinema, '2026-10-22', '2026-10-23');

        $this->assertSame(1, $preview['created']);
        $this->assertSame($ids, Screening::query()->pluck('id')->all());
        $this->assertSame(0, DB::table('screening_prices')->whereNotIn('screening_id', $ids)->count());
        $this->assertSame($generation, app(CatalogCache::class)->generation(CatalogCache::cinema($this->cinema->id)), 'Podgląd nie unieważnia cache.');
    }

    public function test_rules_of_manual_planning_apply_to_each_copied_screening(): void
    {
        $this->add($this->hallA, '2026-10-22', '18:00');
        $this->add($this->hallB, '2026-10-22', '20:00');
        $this->add($this->hallB, '2026-10-22', '02:30');   // na 25.10 02:30 zdarza się dwa razy

        $this->hallA->update(['projection_types' => ['3d']]);
        $this->hallB->seats()->limit(1)->update(['price_category_id' => PriceCategory::factory()->create(['name' => 'Nowa T'])->id]);

        $items = $this->service()->preview($this->cinema, '2026-10-22', '2026-10-25')['items'];

        $this->assertSame(['problem', 'problem', 'problem'], array_column($items, 'status'));
        $this->assertStringContainsString('nie obsługuje projekcji 2d', (string) $items[0]['message']);
        $this->assertStringContainsString('występuje dwa razy', (string) $items[1]['message']);
        $this->assertStringContainsString('Uzupełnij ceny', (string) $items[2]['message']);
    }

    public function test_target_day_must_be_later_than_today_and_source_must_have_screenings(): void
    {
        $this->add($this->hallA, '2026-10-22', '18:00');

        foreach ([['2026-10-22', '2026-10-20', 'target_too_early'], ['2026-10-22', '2026-10-22', 'same_day'], ['2026-10-21', '2026-10-23', 'empty_source']] as [$from, $to, $reason]) {
            try {
                $this->service()->preview($this->cinema, $from, $to);
                $this->fail("Oczekiwano {$reason}.");
            } catch (RepertoireCopyBlockedException $e) {
                $this->assertSame($reason, $e->context()['reason']);
            }
        }
    }
}
