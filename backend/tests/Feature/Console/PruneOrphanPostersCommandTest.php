<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Movie;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprzątanie osieroconych plakatów (Etap 10, blok C2).
 *
 * Każdy test ma na dysku KOMPLET rodzajów plików naraz — używany, sierota, świeża sierota,
 * obca nazwa — bo pomyłka, która kosztuje, to usunięcie pliku, który miał zostać. Sprawdzenie
 * samego "sierota znika" nie wykryłoby komendy, która kasuje cały katalog.
 */
final class PruneOrphanPostersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const USED = 'posters/01j9zzzzzzzzzzzzzzzzzzzzz1.jpg';

    private const ORPHAN = 'posters/01j9zzzzzzzzzzzzzzzzzzzzz2.jpg';

    private const FRESH_ORPHAN = 'posters/01j9zzzzzzzzzzzzzzzzzzzzz3.jpg';

    private const FOREIGN = 'posters/logo-kina.png';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        foreach ([self::USED, self::ORPHAN, self::FRESH_ORPHAN, self::FOREIGN] as $path) {
            Storage::disk('public')->put($path, 'plik');
        }
        $this->age(self::USED, hours: 72);
        $this->age(self::ORPHAN, hours: 30);
        $this->age(self::FOREIGN, hours: 500);
        $this->age(self::FRESH_ORPHAN, hours: 2);

        Movie::factory()->create(['poster_path' => self::USED]);
        Movie::factory()->create(['poster_path' => null]);
    }

    public function test_usuwa_tylko_stara_sierote_o_nazwie_nadawanej_przez_aplikacje(): void
    {
        $this->artisan('cinema:posters:prune')
            ->expectsOutput('usunięty: '.self::ORPHAN)
            ->expectsOutput('Plakaty: sprawdzone 4, używane 1, świeże 1, obce nazwy 1, sieroty 1, usunięte 1')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing(self::ORPHAN);
        Storage::disk('public')->assertExists([self::USED, self::FRESH_ORPHAN, self::FOREIGN]);
    }

    public function test_dry_run_niczego_nie_usuwa(): void
    {
        $this->artisan('cinema:posters:prune --dry-run')
            ->expectsOutput('sierota (bez usuwania): '.self::ORPHAN)
            ->expectsOutput('Plakaty: sprawdzone 4, używane 1, świeże 1, obce nazwy 1, sieroty 1, usunięte 0')
            ->assertSuccessful();

        Storage::disk('public')->assertExists([self::USED, self::ORPHAN, self::FRESH_ORPHAN, self::FOREIGN]);
    }

    public function test_krotsze_okno_obejmuje_swieza_sierote_ale_nigdy_uzywanego_pliku(): void
    {
        $this->artisan('cinema:posters:prune --older-than=1')->assertSuccessful();

        Storage::disk('public')->assertMissing([self::ORPHAN, self::FRESH_ORPHAN]);
        Storage::disk('public')->assertExists([self::USED, self::FOREIGN]);

        // Okno nie schodzi poniżej godziny: 0 nie znaczy "wszystko od razu".
        Storage::disk('public')->put(self::FRESH_ORPHAN, 'plik');
        $this->artisan('cinema:posters:prune --older-than=0')->assertSuccessful();
        Storage::disk('public')->assertExists(self::FRESH_ORPHAN);
    }

    public function test_sprzatanie_jest_w_harmonogramie_raz_na_dobe(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'cinema:posters:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('45 3 * * *', $events->first()->expression);
    }

    private function age(string $path, int $hours): void
    {
        touch(Storage::disk('public')->path($path), now()->subHours($hours)->getTimestamp());
    }
}
