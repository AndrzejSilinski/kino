<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Livewire\Admin\Screenings\RepertoireCopy;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\User;
use App\Services\Admin\ScreeningAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ekran kopiowania repertuaru (Etap 7, blok H): podgląd przed kopiowaniem, raport
 * problemów, świeże sprawdzenie przy kliknięciu, dostęp tylko dla administratora.
 */
final class RepertoireCopyPanelTest extends TestCase
{
    use RefreshDatabase;

    private Cinema $cinema;

    private Hall $hall;

    private Movie $movie;

    private PriceCategory $standard;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['cinema.screening.ads_minutes' => 15, 'cinema.screening.cleanup_buffer_minutes' => 20]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'UTC'));

        $this->standard = PriceCategory::factory()->create();
        $this->cinema = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);
        $this->hall = Hall::factory()->withSeats(1, 2, $this->standard)->for($this->cinema)->create(['name' => 'Sala A', 'projection_types' => ['2d']]);
        $this->movie = Movie::factory()->create(['title' => 'Film T', 'duration_minutes' => 120]);
    }

    private function add(string $date, string $time): Screening
    {
        return app(ScreeningAdminService::class)->create([
            'hall_id' => $this->hall->id, 'movie_id' => $this->movie->id, 'date' => $date, 'time' => $time,
            'projection_type' => ProjectionType::TwoD, 'language_version' => LanguageVersion::Subtitles,
            'prices' => [$this->standard->id => 2500],
        ]);
    }

    public function test_only_admin_opens_copy_screen_and_grid_links_to_it(): void
    {
        $url = route('admin.cinemas.screenings.copy', $this->cinema);

        $this->actingAs(User::factory()->staff($this->cinema)->create(), 'web')->get($url)->assertForbidden();
        $this->get(route('admin.cinemas.screenings.index', $this->cinema))->assertDontSee('Kopiuj dzień');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create(), 'web')->get($url.'?z=2026-10-22')->assertOk()->assertSee('Kopiowanie repertuaru');
        $this->get(route('admin.cinemas.screenings.index', $this->cinema))->assertSee('Kopiuj dzień');
    }

    public function test_preview_then_copy_redirects_to_target_week(): void
    {
        $this->add('2026-10-22', '18:00');
        $this->add('2026-10-22', '21:00');

        Livewire::withQueryParams(['z' => '2026-10-22'])
            ->actingAs(User::factory()->admin()->create())
            ->test(RepertoireCopy::class, ['cinema' => $this->cinema])
            ->assertSet('sourceDate', '2026-10-22')
            ->assertSet('targetDate', '2026-10-29')
            ->call('copy')
            ->assertSet('problem', 'Najpierw zrób podgląd dla wybranych dni.')
            ->call('preview')
            ->assertSee('Do utworzenia:')
            ->assertSee('skopiuje się')
            ->assertSeeHtml('Kopiuj 2 seans(ów)')
            ->call('copy')
            ->assertRedirect(route('admin.cinemas.screenings.index', ['cinema' => $this->cinema, 'od' => '2026-10-29']));

        $this->assertSame(4, Screening::query()->count());
    }

    public function test_changing_dates_invalidates_preview_and_problem_report_disables_copy(): void
    {
        $this->add('2026-10-22', '18:00');
        $this->add('2026-10-23', '19:00');
        // Druga sala bez kolizji: raport ma i "skopiuje się", i problem — przycisk musi być wyłączony.
        $hallB = Hall::factory()->withSeats(1, 1, $this->standard)->for($this->cinema)->create(['name' => 'Sala B', 'projection_types' => ['2d']]);
        app(ScreeningAdminService::class)->create([
            'hall_id' => $hallB->id, 'movie_id' => $this->movie->id, 'date' => '2026-10-22', 'time' => '18:00',
            'projection_type' => ProjectionType::TwoD, 'language_version' => LanguageVersion::Subtitles,
            'prices' => [$this->standard->id => 2500],
        ]);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(RepertoireCopy::class, ['cinema' => $this->cinema])
            ->set('sourceDate', '2026-10-22')
            ->set('targetDate', '2026-10-24')
            ->call('preview')
            ->assertSet('previewFor', '2026-10-22>2026-10-24')
            ->set('targetDate', '2026-10-23')
            ->assertSet('report', null)
            ->call('preview')
            ->assertSee('problem:')
            ->assertSee('nachodzi na inny seans')
            ->assertSee('skopiuje się')
            ->assertViewHas('canCopy', false);

        // Ktoś kliknął "Kopiuj" mimo problemów (np. przez żądanie Livewire): serwis blokuje całość.
        $component->call('copy')->assertSet('problem', fn (?string $p): bool => str_contains((string) $p, 'Nie skopiowano niczego'));
        $this->assertSame(3, Screening::query()->count());
    }

    public function test_state_changed_after_preview_is_caught_at_copy_time(): void
    {
        $this->add('2026-10-22', '18:00');

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(RepertoireCopy::class, ['cinema' => $this->cinema])
            ->set('sourceDate', '2026-10-22')->set('targetDate', '2026-10-23')
            ->call('preview')
            ->assertViewHas('canCopy', true);

        $this->add('2026-10-23', '19:00');   // inny administrator w międzyczasie

        $component->call('copy')
            ->assertNoRedirect()
            ->assertSet('problem', fn (?string $p): bool => str_contains((string) $p, 'Nie skopiowano niczego'))
            ->assertSee('nachodzi na inny seans');

        $this->assertSame(2, Screening::query()->count());
    }

    public function test_copy_rechecks_permission_on_every_call(): void
    {
        $this->add('2026-10-22', '18:00');
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(RepertoireCopy::class, ['cinema' => $this->cinema])
            ->set('sourceDate', '2026-10-22')->set('targetDate', '2026-10-23')
            ->call('preview');

        $this->actingAs(User::factory()->staff($this->cinema)->create());
        $component->call('copy')->assertForbidden();

        $this->assertSame(1, Screening::query()->count());
    }
}
