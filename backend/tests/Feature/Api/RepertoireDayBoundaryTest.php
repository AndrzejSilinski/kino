<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Granice dnia repertuaru w strefie kina (Etap 7, blok G1 — błąd znaleziony przy seederze).
 *
 * Laravel formatuje daty dla PostgreSQL jako 'Y-m-d H:i:s' BEZ strefy (pułapka BN).
 * Carbon w strefie Europe/Warsaw w where() trafiał do SQL jako czas UTC, więc
 * "dzień" w repertuarze był przesunięty o 1–2 h względem kalendarza, który liczy
 * dni poprawnie. Seans o 00:30 czasu lokalnego lądował na liście poprzedniego dnia.
 */
final class RepertoireDayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    }

    public function test_screenings_around_local_midnight_belong_to_local_days_in_list_and_calendar(): void
    {
        $cinema = Cinema::factory()->create(['timezone' => 'Europe/Warsaw', 'is_active' => true]);
        // Dwie sale: w jednej sali te seanse (godzina odstępu) naruszyłyby screenings_no_overlap.
        $halls = Hall::factory()->count(2)->withSeats(1, 2)->for($cinema)->create();

        $late = CarbonImmutable::parse('2026-10-02 23:30', 'Europe/Warsaw');   // 21:30 UTC
        $afterMidnight = CarbonImmutable::parse('2026-10-03 00:30', 'Europe/Warsaw'); // 22:30 UTC dnia 2.10

        foreach ([$late, $afterMidnight] as $index => $start) {
            Screening::factory()->for($halls[$index])->create([
                'starts_at' => $start->utc(),
                'ends_at' => $start->utc()->addMinutes(135),
                'slot_ends_at' => $start->utc()->addMinutes(155),
            ]);
        }

        $url = '/api/v1/cinemas/'.$cinema->getRouteKey();

        $this->assertSame(['2026-10-02T21:30:00+00:00'], $this->startsOn($url, '2026-10-02'));
        $this->assertSame(['2026-10-02T22:30:00+00:00'], $this->startsOn($url, '2026-10-03'));

        $dates = collect($this->getJson($url.'/screening-dates')->assertOk()->json('data'))->pluck('screenings_count', 'date')->all();
        $this->assertSame(['2026-10-02' => 1, '2026-10-03' => 1], $dates, 'Kalendarz i lista dnia muszą liczyć dni tak samo.');
    }

    /** @return list<string> starts_at seansów z listy dnia, znormalizowane do UTC */
    private function startsOn(string $url, string $date): array
    {
        return collect($this->getJson($url.'/screenings?date='.$date)->assertOk()->json('data'))
            ->map(fn (array $item): string => CarbonImmutable::parse($item['starts_at'])->utc()->toIso8601String())
            ->all();
    }
}
