<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\InvalidScreeningException;
use App\Support\ScreeningSlot;
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test jednostkowy walidacji kolizji seansów w sali (wymóg 5.2 zadania), Etap 7, blok G1.
 *
 * Bez bazy i bez Laravela: czas seansu (reklamy + film + sprzątanie), przedziały
 * półotwarte i zamiana godziny lokalnej kina na UTC przy zmianach czasu.
 * Ten sam predykat sprawdza test integracyjny na PostgreSQL (ScreeningAdminServiceTest).
 */
final class ScreeningTimelineTest extends TestCase
{
    private function timeline(): ScreeningTimeline
    {
        return new ScreeningTimeline(adsMinutes: 15, cleanupMinutes: 20);
    }

    private function slotAt(string $utc, int $duration = 120): ScreeningSlot
    {
        return $this->timeline()->slot(CarbonImmutable::parse($utc, 'UTC'), $duration);
    }

    public function test_slot_adds_ads_to_film_end_and_cleanup_to_room_release(): void
    {
        $slot = $this->slotAt('2026-10-02 18:00', 120);

        $this->assertSame('2026-10-02 18:00', $slot->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-10-02 20:15', $slot->endsAt->format('Y-m-d H:i'), 'Koniec filmu: start + 15 min reklam + 120 min.');
        $this->assertSame('2026-10-02 20:35', $slot->slotEndsAt->format('Y-m-d H:i'), 'Sala wolna po 20 min sprzątania.');
    }

    public function test_slot_is_stored_in_utc_even_for_local_input(): void
    {
        $slot = $this->timeline()->slot(CarbonImmutable::parse('2026-10-02 20:00:45', 'Europe/Warsaw'), 100);

        $this->assertSame('UTC', $slot->startsAt->getTimezone()->getName());
        $this->assertSame('2026-10-02 18:00:00', $slot->startsAt->format('Y-m-d H:i:s'), 'Sekundy obcięte, moment w UTC.');
    }

    /** @return iterable<string, array{string, int, string, int, bool}> */
    public static function pairs(): iterable
    {
        // Pierwszy: 18:00, 120 min -> sala zajęta [18:00, 20:35).
        yield 'drugi zaczyna się dokładnie, gdy sala się zwalnia (styk)' => ['18:00', 120, '20:35', 90, false];
        yield 'drugi zaczyna się minutę przed zwolnieniem sali' => ['18:00', 120, '20:34', 90, true];
        yield 'drugi zaczyna się po końcu filmu, ale w czasie sprzątania' => ['18:00', 120, '20:20', 90, true];
        yield 'drugi kończy sprzątanie dokładnie, gdy pierwszy się zaczyna' => ['18:00', 120, '15:25', 120, false];
        yield 'drugi kończy sprzątanie minutę po starcie pierwszego' => ['18:00', 120, '15:26', 120, true];
        yield 'drugi w całości w środku pierwszego' => ['18:00', 180, '18:30', 60, true];
        yield 'drugi obejmuje pierwszy' => ['18:30', 60, '18:00', 180, true];
        yield 'ten sam start' => ['18:00', 120, '18:00', 120, true];
        yield 'daleko od siebie' => ['12:00', 90, '18:00', 90, false];
    }

    #[DataProvider('pairs')]
    public function test_overlap_is_half_open_and_symmetric(string $firstStart, int $firstDuration, string $secondStart, int $secondDuration, bool $expected): void
    {
        $first = $this->slotAt('2026-10-02 '.$firstStart, $firstDuration);
        $second = $this->slotAt('2026-10-02 '.$secondStart, $secondDuration);

        $this->assertSame($expected, $first->overlaps($second));
        $this->assertSame($expected, $second->overlaps($first), 'Kolizja musi być symetryczna.');
    }

    public function test_buffers_change_the_answer(): void
    {
        $noBuffers = new ScreeningTimeline(adsMinutes: 0, cleanupMinutes: 0);
        $first = $noBuffers->slot(CarbonImmutable::parse('2026-10-02 18:00', 'UTC'), 120);
        $second = $noBuffers->slot(CarbonImmutable::parse('2026-10-02 20:00', 'UTC'), 90);

        $this->assertFalse($first->overlaps($second), 'Bez bufora film 18:00–20:00 nie koliduje z 20:00.');
        $this->assertTrue($this->slotAt('2026-10-02 18:00')->overlaps($this->slotAt('2026-10-02 20:00', 90)), 'Z reklamami i sprzątaniem już tak.');
    }

    public function test_local_time_becomes_utc_in_summer_and_winter(): void
    {
        $this->assertSame('2026-07-10 17:30', $this->timeline()->localStart('2026-07-10', '19:30', 'Europe/Warsaw')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-10 18:30', $this->timeline()->localStart('2026-12-10', '19:30', 'Europe/Warsaw')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-10 19:30', $this->timeline()->localStart('2026-12-10', '19:30', 'UTC')->format('Y-m-d H:i'));
    }

    public function test_night_film_spans_the_short_night_of_spring_clock_change(): void
    {
        // 29.03.2026: 02:00 -> 03:00. Start 01:00 CET (00:00 UTC), 120 min + bufory.
        $start = $this->timeline()->localStart('2026-03-29', '01:00', 'Europe/Warsaw');
        $slot = $this->timeline()->slot($start, 120);

        $this->assertSame('02:35', $slot->slotEndsAt->format('H:i'), 'W UTC 155 minut to po prostu 155 minut.');
        $this->assertSame('04:35', $slot->slotEndsAt->setTimezone('Europe/Warsaw')->format('H:i'), 'Lokalnie godzina "znika".');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedTimes(): iterable
    {
        yield 'godzina nieistniejąca (wiosenna zmiana czasu)' => ['2026-03-29', '02:30', 'time_nonexistent'];
        yield 'godzina podwójna (jesienna zmiana czasu)' => ['2026-10-25', '02:30', 'time_ambiguous'];
        yield 'nie ma 30 lutego' => ['2026-02-30', '18:00', 'bad_time'];
        yield 'nie ma 24:10' => ['2026-10-02', '24:10', 'bad_time'];
        yield 'zły format' => ['02.10.2026', '18:00', 'bad_time'];
    }

    #[DataProvider('rejectedTimes')]
    public function test_times_that_do_not_exist_once_are_rejected(string $date, string $time, string $reason): void
    {
        try {
            $this->timeline()->localStart($date, $time, 'Europe/Warsaw');
            $this->fail('Oczekiwano InvalidScreeningException.');
        } catch (InvalidScreeningException $e) {
            $this->assertSame($reason, $e->context()['reason']);
            $this->assertSame('startsAt', $e->field());
        }
    }

    public function test_hours_around_clock_changes_are_accepted(): void
    {
        $this->assertSame('2026-03-29 01:00', $this->timeline()->localStart('2026-03-29', '03:00', 'Europe/Warsaw')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 23:59', $this->timeline()->localStart('2026-10-25', '01:59', 'Europe/Warsaw')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 02:00', $this->timeline()->localStart('2026-10-25', '03:00', 'Europe/Warsaw')->format('Y-m-d H:i'));
    }
}
