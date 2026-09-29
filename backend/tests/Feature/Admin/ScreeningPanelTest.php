<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Livewire\Admin\Screenings\ScreeningForm;
use App\Livewire\Admin\Screenings\ScreeningWeek;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Repertuar w panelu (Etap 7, blok G2): siatka tygodnia w strefie kina, formularz
 * seansu z cennikiem, komunikaty kolizji i blokad, dostęp administratora i obsługi.
 * Reguły planowania sprawdza ScreeningAdminServiceTest; tu — komponenty i widoki.
 */
final class ScreeningPanelTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));

        $this->standard = PriceCategory::factory()->create(['name' => 'Standard T']);
        $this->cinema = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);
        $this->hall = Hall::factory()->withSeats(1, 2, $this->standard)->for($this->cinema)->create(['name' => 'Sala A', 'projection_types' => ['2d', '3d']]);
        $this->movie = Movie::factory()->create(['title' => 'Film Testowy', 'duration_minutes' => 120]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Seans o podanej godzinie czasu warszawskiego, z ceną 25 zł. */
    private function screeningAt(string $local, ?Hall $hall = null): Screening
    {
        $start = CarbonImmutable::parse($local, 'Europe/Warsaw')->utc();
        $screening = Screening::factory()->for($hall ?? $this->hall)->for($this->movie)->create([
            'starts_at' => $start, 'ends_at' => $start->addMinutes(135), 'slot_ends_at' => $start->addMinutes(155),
        ]);
        ScreeningPrice::factory()->create(['screening_id' => $screening->id, 'price_category_id' => $this->standard->id, 'price' => 2500]);

        return $screening;
    }

    // ─── Dostęp ──────────────────────────────────────────────────────────

    public function test_admin_plans_staff_of_this_cinema_only_views_others_are_denied(): void
    {
        $screening = $this->screeningAt('2026-10-02 18:00');
        $grid = route('admin.cinemas.screenings.index', $this->cinema);
        $create = route('admin.cinemas.screenings.create', $this->cinema);
        $edit = route('admin.screenings.edit', $screening);

        $this->get($grid)->assertRedirect(route('admin.login'));

        $staff = User::factory()->staff($this->cinema)->create();
        $this->actingAs($staff, 'web')->get($grid)->assertOk()->assertSee('Film Testowy')->assertDontSee($edit)->assertDontSee('Dodaj seans');
        $this->get(route('admin.dashboard'))->assertSee($grid);
        $this->get($create)->assertForbidden();
        $this->get($edit)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $otherStaff = User::factory()->staff()->create();
        $this->actingAs($otherStaff, 'web')->get($grid)->assertForbidden();
        Livewire::actingAs($otherStaff)->test(ScreeningWeek::class, ['cinema' => $this->cinema])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin(), 'web')->get($grid)->assertOk()->assertSee($edit)->assertSee('Dodaj seans');
        $this->get($create)->assertOk();
        $this->get($edit)->assertOk();
        $this->get(route('admin.cinemas.index'))->assertSee($grid);
    }

    // ─── Siatka ──────────────────────────────────────────────────────────

    public function test_week_grid_puts_screenings_in_local_days_and_moves_by_week(): void
    {
        $this->screeningAt('2026-10-02 23:30');
        $otherHall = Hall::factory()->withSeats(1, 1, $this->standard)->for($this->cinema)->create(['name' => 'Sala B']);
        $afterMidnight = $this->screeningAt('2026-10-03 00:30', $otherHall);
        $this->screeningAt('2026-10-09 18:00');   // już w następnym tygodniu widoku
        // Brzegi tygodnia (dziś = 1.10 w Warszawie): 01:00 pierwszego dnia jest w UTC jeszcze
        // 30.09, a 01:00 ósmego dnia jest w UTC jeszcze 7.10 — granice liczone w UTC to rozstrzygają.
        $this->screeningAt('2026-10-01 01:00', $otherHall);
        $this->screeningAt('2026-10-08 01:15', $otherHall);

        $component = Livewire::actingAs($this->admin())->test(ScreeningWeek::class, ['cinema' => $this->cinema]);
        $component->assertSee('23:30 Film Testowy')->assertSee('00:30 Film Testowy')->assertDontSee('18:00 Film Testowy')
            ->assertSee('01:00 Film Testowy')->assertDontSee('01:15 Film Testowy');

        $cells = $component->viewData('grid');
        $this->assertArrayHasKey('2026-10-03', $cells[$otherHall->id], 'Seans 00:30 czasu lokalnego należy do 3.10, choć w UTC to 2.10.');
        $this->assertSame($afterMidnight->id, $cells[$otherHall->id]['2026-10-03'][0]->id);

        $component->set('from', '2026-10-08')->assertSee('18:00 Film Testowy')->assertDontSee('23:30 Film Testowy');
        $component->set('from', '2026-02-30')->assertSee('23:30 Film Testowy');   // zła data = dziś
    }

    // ─── Formularz ───────────────────────────────────────────────────────

    public function test_admin_adds_screening_with_prices_in_zloty(): void
    {
        // Sala "0" jest pierwsza alfabetycznie: bez parametru ?sala formularz wybrałby właśnie ją.
        Hall::factory()->withSeats(1, 1, $this->standard)->for($this->cinema)->create(['name' => 'Sala 0']);

        Livewire::withQueryParams(['sala' => $this->hall->id, 'data' => '2026-10-05'])
            ->actingAs($this->admin())
            ->test(ScreeningForm::class, ['cinema' => $this->cinema])
            ->assertSet('hallId', (string) $this->hall->id)
            ->assertSet('date', '2026-10-05')
            ->assertSet('projectionType', '2d')
            ->set('movieId', (string) $this->movie->id)
            ->set('time', '19:30')
            ->assertSee('koniec 21:45, sala wolna po sprzątaniu o 22:05')
            ->set('prices.'.$this->standard->id, '19,99')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.cinemas.screenings.index', ['cinema' => $this->cinema, 'od' => '2026-10-05']));

        $screening = Screening::query()->sole();
        $this->assertSame('2026-10-05 17:30', $screening->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(1999, $screening->prices()->value('price'), '19,99 zł = 1999 gr, bez błędu float.');
    }

    public function test_conflict_and_validation_messages_are_shown_at_fields(): void
    {
        $this->screeningAt('2026-10-05 18:00');

        $component = Livewire::actingAs($this->admin())->test(ScreeningForm::class, ['cinema' => $this->cinema])
            ->set('hallId', (string) $this->hall->id)
            ->set('movieId', (string) $this->movie->id)
            ->set('date', '2026-10-05')
            ->set('time', '20:00')
            ->set('prices.'.$this->standard->id, '25')
            ->call('save')
            ->assertHasErrors(['time'])
            ->assertSee('Seans nachodzi na inny seans w tej sali')
            ->assertSee('2026-10-05 18:00–20:35');

        $component->set('time', '21:00')->set('prices.'.$this->standard->id, '25 zł')
            ->call('save')
            ->assertHasErrors(['prices.'.$this->standard->id])
            ->assertSee('Cena w złotych, np. 25 albo 25,50.');

        $component->set('prices.'.$this->standard->id, '25')->set('date', '2027-03-28')->set('time', '02:30')
            ->call('save')
            ->assertHasErrors(['time'])
            ->assertSee('nie istnieje w strefie Europe/Warsaw');

        // Sala innego kina podstawiona w żądaniu Livewire: serwis nie zna kontekstu kina formularza.
        $foreignHall = Hall::factory()->withSeats(1, 1, $this->standard)->create(['projection_types' => ['2d']]);
        $component->set('date', '2026-10-06')->set('time', '12:00')->set('hallId', (string) $foreignHall->id)
            ->set('projectionType', '2d')->set('prices', [$this->standard->id => '25'])
            ->call('save')
            ->assertHasErrors(['hallId']);

        $this->assertSame(1, Screening::query()->count());
    }

    public function test_changing_hall_rebuilds_price_rows_and_projection(): void
    {
        $vip = PriceCategory::factory()->create(['name' => 'VIP T']);
        $imaxHall = Hall::factory()->withSeats(1, 1, $vip)->for($this->cinema)->create(['name' => 'IMAX', 'projection_types' => ['imax']]);
        $last = $this->screeningAt('2026-10-02 18:00', $imaxHall);
        $last->prices()->update(['price_category_id' => $vip->id, 'price' => 4150]);

        Livewire::actingAs($this->admin())->test(ScreeningForm::class, ['cinema' => $this->cinema])
            ->set('hallId', (string) $this->hall->id)
            ->set('prices.'.$this->standard->id, '30')
            ->set('hallId', (string) $imaxHall->id)
            ->assertSet('projectionType', 'imax')
            ->assertSet('prices', [$vip->id => '41,50'])
            ->assertSee('VIP T')
            ->assertDontSee('Standard T');
    }

    public function test_edit_form_is_locked_when_screening_has_sales_and_cancel_explains_bookings(): void
    {
        $screening = $this->screeningAt('2026-10-05 18:00');
        Booking::factory()->paid()->create(['screening_id' => $screening->id]);

        Livewire::actingAs($this->admin())->test(ScreeningForm::class, ['screening' => $screening])
            ->assertSet('time', '18:00')
            ->assertSet('prices', [$this->standard->id => '25,00'])
            ->assertSee('Zmiany zablokowane.')
            ->set('time', '19:00')
            ->call('save')
            ->assertSet('problem', fn (?string $problem): bool => str_contains((string) $problem, 'Seans ma sprzedaż'))
            // Odwołanie seansu z rezerwacjami jest teraz możliwe, ale za potwierdzeniem,
            // które pokazuje LICZBY (Etap 9, decyzja 347).
            ->call('askMassCancel')
            ->assertSet('confirmingMassCancel', true)
            ->assertSee('Anulowanych zostanie')
            ->assertSee('dostaną maila i powiadomienie push');

        $this->assertSame('2026-10-05 16:00', $screening->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(ScreeningStatus::Scheduled, $screening->fresh()->status);
    }

    public function test_confirmation_alone_changes_nothing(): void
    {
        // Samo otwarcie potwierdzenia nie może niczego anulować: to jedyna akcja
        // w panelu, która jednym kliknięciem rusza cudze zakupy.
        $screening = $this->screeningAt('2026-10-05 18:00');
        $booking = Booking::factory()->paid()->create(['screening_id' => $screening->id]);

        Livewire::actingAs($this->admin())->test(ScreeningForm::class, ['screening' => $screening])
            ->call('askMassCancel')
            ->call('dismissMassCancel')
            ->assertSet('confirmingMassCancel', false);

        $this->assertSame(ScreeningStatus::Scheduled, $screening->fresh()->status);
        $this->assertSame(BookingStatus::Paid, $booking->fresh()->status);
    }

    public function test_cancel_frees_the_slot_and_returns_to_grid(): void
    {
        $screening = $this->screeningAt('2026-10-05 18:00');

        Livewire::actingAs($this->admin())->test(ScreeningForm::class, ['screening' => $screening])
            ->call('askMassCancel')
            ->set('cancelReason', 'Awaria projektora w sali A.')
            ->call('cancelScreeningWithBookings')
            ->assertRedirect(route('admin.cinemas.screenings.index', ['cinema' => $this->cinema, 'od' => '2026-10-05']));

        $this->assertSame(ScreeningStatus::Cancelled, $screening->fresh()->status);

        Livewire::actingAs($this->admin())->test(ScreeningWeek::class, ['cinema' => $this->cinema])
            ->assertSeeHtml('is-cancelled');
    }

    public function test_actions_check_permission_again_and_ids_are_locked(): void
    {
        $screening = $this->screeningAt('2026-10-05 18:00');
        $staff = User::factory()->staff($this->cinema)->create();
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(ScreeningForm::class, ['screening' => $screening]);
        $this->actingAs($staff);
        $component->call('cancelScreeningWithBookings')->assertForbidden();
        $this->assertSame(ScreeningStatus::Scheduled, $screening->fresh()->status);

        $component = Livewire::actingAs($admin)->test(ScreeningForm::class, ['screening' => $screening]);
        $this->actingAs($staff);
        $component->set('time', '19:00')->call('save')->assertForbidden();

        // Siatka sprawdza uprawnienie przy każdym odświeżeniu, nie tylko przy otwarciu.
        $otherStaff = User::factory()->staff()->create();
        $grid = Livewire::actingAs($admin)->test(ScreeningWeek::class, ['cinema' => $this->cinema]);
        $this->actingAs($otherStaff);
        $grid->call('$refresh')->assertForbidden();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($admin)->test(ScreeningForm::class, ['screening' => $screening])->set('screeningId', $screening->id + 1);
    }
}
