<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ScreeningStatus;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cinema:screenings:finish — oznaczanie zakończonych seansów.
 *
 * Każdy seans dostaje własną salę (fabryka tworzy ją domyślnie), więc
 * dowolne godziny nie naruszają constraintu EXCLUDE na screenings.
 */
final class FinishScreeningsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_tylko_zaplanowane_seanse_po_koncu_filmu_dostaja_status_finished(): void
    {
        $ended = $this->screening(startedMinutesAgo: 200);
        $running = $this->screening(startedMinutesAgo: 60);
        $future = $this->screening(startedMinutesAgo: -120);
        $cancelled = $this->screening(startedMinutesAgo: 200, status: ScreeningStatus::Cancelled);

        $this->artisan('cinema:screenings:finish')
            ->expectsOutput('Seanse oznaczone jako zakończone: 1')
            ->assertSuccessful();

        $this->assertSame(ScreeningStatus::Finished, $ended->refresh()->status);
        $this->assertSame(ScreeningStatus::Scheduled, $running->refresh()->status);
        $this->assertSame(ScreeningStatus::Scheduled, $future->refresh()->status);
        // Odwołany seans zostaje odwołany: finished nie może ukryć odwołania w raportach.
        $this->assertSame(ScreeningStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_ponowne_uruchomienie_niczego_nie_zmienia(): void
    {
        $this->screening(startedMinutesAgo: 200);

        $this->artisan('cinema:screenings:finish')->assertSuccessful();

        $this->artisan('cinema:screenings:finish')
            ->expectsOutput('Seanse oznaczone jako zakończone: 0')
            ->assertSuccessful();
    }

    private function screening(int $startedMinutesAgo, ScreeningStatus $status = ScreeningStatus::Scheduled): Screening
    {
        $startsAt = CarbonImmutable::now()->subMinutes($startedMinutesAgo);

        return Screening::factory()->create([
            'starts_at' => $startsAt,
            // 135 minut: film (120) z blokiem reklam (15), jak w ScreeningFactory.
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
            'status' => $status,
        ]);
    }
}
