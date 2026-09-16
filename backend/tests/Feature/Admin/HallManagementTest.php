<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ProjectionType;
use App\Livewire\Admin\Halls\HallForm;
use App\Livewire\Admin\Halls\HallIndex;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\User;
use App\Support\CatalogCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Zarządzanie salami w panelu (Etap 7, blok D). Układ miejsc: blok E.
 */
final class HallManagementTest extends TestCase
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

    public function test_admin_creates_hall_with_ordered_projection_types_and_empty_grid(): void
    {
        $cinema = Cinema::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(HallForm::class, ['cinema' => $cinema])
            ->set('name', 'Sala 7')
            ->set('projectionTypes', ['imax', '2d'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.cinemas.halls.index', $cinema));

        $hall = Hall::query()->where('name', 'Sala 7')->firstOrFail();

        $this->assertSame($cinema->id, $hall->cinema_id);
        $this->assertSame(['2d', 'imax'], $hall->projection_types, 'Kolejność jak w enumie, niezależnie od kliknięć.');
        $this->assertSame([0, 0], [$hall->grid_rows, $hall->grid_cols]);
        $this->assertSame(1, app(CatalogCache::class)->generation(CatalogCache::cinema($cinema->id)));
    }

    public function test_hall_name_is_unique_within_cinema_only(): void
    {
        $first = Cinema::factory()->create();
        $second = Cinema::factory()->create();
        Hall::factory()->for($first)->create(['name' => 'Sala 1']);

        Livewire::actingAs($admin = $this->admin())
            ->test(HallForm::class, ['cinema' => $first])
            ->set('name', 'Sala 1')
            ->call('save')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSee('Sala o tej nazwie już istnieje w tym kinie.');

        Livewire::actingAs($admin)
            ->test(HallForm::class, ['cinema' => $second])
            ->set('name', 'Sala 1')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_projection_types_are_required_and_must_be_known(): void
    {
        $cinema = Cinema::factory()->create();

        Livewire::actingAs($admin = $this->admin())
            ->test(HallForm::class, ['cinema' => $cinema])
            ->set('name', 'Sala A')
            ->set('projectionTypes', [])
            ->call('save')
            ->assertHasErrors(['projectionTypes' => 'required'])
            ->set('projectionTypes', ['4dx'])
            ->call('save')
            ->assertHasErrors('projectionTypes.0')
            ->assertSee('Nieznany typ projekcji.');

        $this->assertSame(0, Hall::query()->count());
    }

    public function test_removing_projection_type_used_by_upcoming_screening_is_blocked(): void
    {
        $hall = Hall::factory()->create(['projection_types' => ['2d', '3d']]);
        Screening::factory()->for($hall)->create(['projection_type' => ProjectionType::TwoD]);

        Livewire::actingAs($admin = $this->admin())
            ->test(HallForm::class, ['hall' => $hall])
            ->assertSet('projectionTypes', ['2d', '3d'])
            ->set('projectionTypes', ['3d'])
            ->call('save')
            ->assertHasErrors('projectionTypes')
            ->assertSee('Nie można usunąć typu projekcji (2d)');

        $this->assertSame(['2d', '3d'], $hall->refresh()->projection_types);

        // Typ, którego nie używa żaden nadchodzący seans, można usunąć.
        Livewire::actingAs($admin)
            ->test(HallForm::class, ['hall' => $hall])
            ->set('projectionTypes', ['2d'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['2d'], $hall->refresh()->projection_types);
    }

    public function test_hall_deactivation_is_blocked_with_upcoming_screening(): void
    {
        $hall = Hall::factory()->create(['name' => 'Sala Zajęta']);
        Screening::factory()->for($hall)->create();

        Livewire::actingAs($this->admin())
            ->test(HallIndex::class, ['cinema' => $hall->cinema])
            ->call('toggleActive', $hall->id)
            ->assertSee('Nie można wyłączyć sali, która ma nadchodzące seanse (1).');

        $this->assertTrue($hall->refresh()->is_active);
    }

    /** Id sali przychodzi z przeglądarki: lista kina A nie może przełączać sal kina B. */
    public function test_hall_of_another_cinema_cannot_be_toggled_from_this_list(): void
    {
        $listed = Cinema::factory()->create();
        $foreign = Hall::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin())
            ->test(HallIndex::class, ['cinema' => $listed])
            ->call('toggleActive', $foreign->id);
    }

    public function test_hall_components_deny_staff_without_route_middleware(): void
    {
        $hall = Hall::factory()->create();
        $staff = User::factory()->staff($hall->cinema)->create();

        Livewire::actingAs($staff)->test(HallIndex::class, ['cinema' => $hall->cinema])->assertForbidden();
        Livewire::actingAs($staff)->test(HallForm::class, ['cinema' => $hall->cinema])->assertForbidden();
        Livewire::actingAs($staff)->test(HallForm::class, ['hall' => $hall])->assertForbidden();
    }

    /** Zmiana nazwy sali trafia do publicznego repertuaru od razu (generacja kina). */
    public function test_renamed_hall_is_visible_in_cached_repertoire_immediately(): void
    {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');

        $hall = Hall::factory()->create(['name' => 'Sala Przed']);
        $screening = Screening::factory()->for($hall)->create();
        $date = $screening->starts_at->copy()->setTimezone($hall->cinema->timezone)->toDateString();
        $url = '/api/v1/cinemas/'.$hall->cinema->slug.'/screenings?date='.$date;

        $this->assertSame('Sala Przed', $this->getJson($url)->json('data.0.hall.name'));

        Livewire::actingAs($this->admin())
            ->test(HallForm::class, ['hall' => $hall])
            ->set('name', 'Sala Po')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Sala Po', $this->getJson($url)->json('data.0.hall.name'));
    }
}
