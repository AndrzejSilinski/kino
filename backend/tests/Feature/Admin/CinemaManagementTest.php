<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ScreeningStatus;
use App\Livewire\Admin\Cinemas\CinemaForm;
use App\Livewire\Admin\Cinemas\CinemaIndex;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\User;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Zarządzanie kinami w panelu (Etap 7, blok D).
 *
 * Dostęp sprawdzamy dwa razy: przez HTTP (middleware can: na trasie) i przez
 * Livewire::test, który działa BEZ middleware — tam broni wyłącznie authorize()
 * w komponencie (pułapka BA).
 */
final class CinemaManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Seans jutro w nowej sali kina — "nadchodzący" dla reguł blokady. */
    private function upcomingScreeningIn(Cinema $cinema): Screening
    {
        return Screening::factory()->for(Hall::factory()->for($cinema))->create();
    }

    // ─── Dostęp ──────────────────────────────────────────────────────────

    public function test_structure_routes_are_for_admin_only(): void
    {
        $cinema = Cinema::factory()->create();
        $hall = Hall::factory()->for($cinema)->create();
        $urls = [
            route('admin.cinemas.index'),
            route('admin.cinemas.create'),
            route('admin.cinemas.edit', $cinema),
            route('admin.cinemas.halls.index', $cinema),
            route('admin.cinemas.halls.create', $cinema),
            route('admin.halls.edit', $hall),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }

        $staff = User::factory()->staff($cinema)->create();
        foreach ($urls as $url) {
            $this->actingAs($staff, 'web')->get($url)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();

        $admin = $this->admin();
        foreach ($urls as $url) {
            $this->actingAs($admin, 'web')->get($url)->assertOk();
        }
    }

    public function test_components_deny_staff_without_route_middleware(): void
    {
        $cinema = Cinema::factory()->create();
        $staff = User::factory()->staff($cinema)->create();

        Livewire::actingAs($staff)->test(CinemaIndex::class)->assertForbidden();
        Livewire::actingAs($staff)->test(CinemaForm::class)->assertForbidden();
        Livewire::actingAs($staff)->test(CinemaForm::class, ['cinema' => $cinema])->assertForbidden();
    }

    public function test_locked_cinema_id_cannot_be_changed_from_the_browser(): void
    {
        $edited = Cinema::factory()->create();
        $other = Cinema::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())
            ->test(CinemaForm::class, ['cinema' => $edited])
            ->set('cinemaId', $other->id);
    }

    // ─── Lista ───────────────────────────────────────────────────────────

    public function test_search_treats_like_wildcards_literally(): void
    {
        Cinema::factory()->create(['name' => 'Kino 50% taniej', 'city' => 'Łódź']);
        Cinema::factory()->create(['name' => 'Kino Luna', 'city' => 'Poznań']);

        Livewire::actingAs($this->admin())
            ->test(CinemaIndex::class)
            ->set('search', '50%')
            ->assertSee('Kino 50% taniej')
            ->assertDontSee('Kino Luna')
            ->set('search', 'luna')
            ->assertSee('Kino Luna')
            ->set('search', '_')
            ->assertSee('Brak kin spełniających kryteria.');
    }

    public function test_search_is_read_from_query_string(): void
    {
        Livewire::withQueryParams(['q' => 'Luna'])
            ->actingAs($this->admin())
            ->test(CinemaIndex::class)
            ->assertSet('search', 'Luna');
    }

    // ─── Tworzenie i edycja ──────────────────────────────────────────────

    public function test_create_validates_with_polish_messages(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CinemaForm::class)
            ->set('name', '')
            ->set('city', '')
            ->set('address', '')
            ->set('timezone', 'Mars/Olympus')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'city' => 'required', 'address' => 'required', 'timezone' => 'timezone'])
            ->assertSee('Pole nazwa kina jest wymagane.')
            ->assertSee('Wybierz strefę czasową z listy');

        $this->assertSame(0, Cinema::query()->count());
    }

    public function test_create_generates_unique_slug_and_invalidates_cinema_list(): void
    {
        Cinema::factory()->create(['slug' => 'krakow-kino-nowe']);

        Livewire::actingAs($this->admin())
            ->test(CinemaForm::class)
            ->set('name', 'Kino Nowe')
            ->set('city', 'Kraków')
            ->set('address', 'ul. Floriańska 1')
            ->set('timezone', 'Europe/Warsaw')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.cinemas.index'));

        $created = Cinema::query()->where('name', 'Kino Nowe')->firstOrFail();

        $this->assertSame('krakow-kino-nowe-2', $created->slug);
        $this->assertTrue($created->is_active);
        $this->assertSame(1, app(CatalogCache::class)->generation(CatalogCache::CINEMAS));
    }

    public function test_update_keeps_slug_and_invalidates_cinema_generations(): void
    {
        $cinema = Cinema::factory()->create(['name' => 'Kino Stare', 'slug' => 'gdansk-kino-stare']);

        Livewire::actingAs($this->admin())
            ->test(CinemaForm::class, ['cinema' => $cinema])
            ->assertSet('name', 'Kino Stare')
            ->set('name', 'Kino Nowa Nazwa')
            ->call('save')
            ->assertHasNoErrors();

        $cinema->refresh();
        $this->assertSame('Kino Nowa Nazwa', $cinema->name);
        $this->assertSame('gdansk-kino-stare', $cinema->slug, 'Slug nie może się zmienić przy edycji.');
        $this->assertSame(1, app(CatalogCache::class)->generation(CatalogCache::CINEMAS));
        $this->assertSame(1, app(CatalogCache::class)->generation(CatalogCache::cinema($cinema->id)));
    }

    public function test_timezone_change_is_blocked_while_cinema_has_upcoming_screenings(): void
    {
        $busy = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);
        $this->upcomingScreeningIn($busy);
        $empty = Cinema::factory()->create(['timezone' => 'Europe/Warsaw']);

        Livewire::actingAs($admin = $this->admin())
            ->test(CinemaForm::class, ['cinema' => $busy])
            ->set('timezone', 'Europe/London')
            ->call('save')
            ->assertHasErrors('timezone')
            ->assertSee('Nie można zmienić strefy czasowej kina');

        $this->assertSame('Europe/Warsaw', $busy->refresh()->timezone);

        Livewire::actingAs($admin)
            ->test(CinemaForm::class, ['cinema' => $empty])
            ->set('timezone', 'Europe/London')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Europe/London', $empty->refresh()->timezone);
    }

    // ─── Włączanie i wyłączanie ──────────────────────────────────────────

    public function test_deactivation_is_blocked_until_upcoming_screenings_are_cancelled(): void
    {
        $cinema = Cinema::factory()->create(['name' => 'Kino Zajęte']);
        $screening = $this->upcomingScreeningIn($cinema);

        $component = Livewire::actingAs($this->admin())->test(CinemaIndex::class)
            ->call('toggleActive', $cinema->id)
            ->assertSee('Nie można wyłączyć kina, które ma nadchodzące seanse (1).');

        $this->assertTrue($cinema->refresh()->is_active);

        $screening->update(['status' => ScreeningStatus::Cancelled]);

        $component->call('toggleActive', $cinema->id)
            ->assertSet('problem', null)
            ->assertSee('Wyłączono kino Kino Zajęte.');

        $this->assertFalse($cinema->refresh()->is_active);
    }

    /** Wyłączenie działa od razu także w publicznym API — przez generację, nie po TTL. */
    public function test_deactivated_cinema_disappears_from_cached_public_list_immediately(): void
    {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');

        $cinema = Cinema::factory()->create();

        $this->getJson('/api/v1/cinemas')->assertOk()->assertJsonFragment(['slug' => $cinema->slug]);

        Livewire::actingAs($this->admin())->test(CinemaIndex::class)->call('toggleActive', $cinema->id);

        $this->getJson('/api/v1/cinemas')->assertOk()->assertJsonMissing(['slug' => $cinema->slug]);
    }
}
