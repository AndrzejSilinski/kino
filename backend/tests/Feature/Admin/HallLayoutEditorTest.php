<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Halls\HallLayoutEditor;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Komponent edytora układu sali (Etap 7, blok E). Logikę zapisu sprawdza
 * HallLayoutServiceTest; tu: dostęp, generator, przekazanie układu i komunikaty.
 */
final class HallLayoutEditorTest extends TestCase
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

    public function test_route_and_component_are_admin_only(): void
    {
        $hall = Hall::factory()->create();
        $staff = User::factory()->staff($hall->cinema)->create();

        $this->actingAs($staff, 'web')->get(route('admin.halls.layout', $hall))->assertForbidden();
        Livewire::actingAs($staff)->test(HallLayoutEditor::class, ['hall' => $hall])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin(), 'web')->get(route('admin.halls.layout', $hall))
            ->assertOk()
            ->assertSee('Generator układu');
    }

    public function test_generator_returns_draft_without_saving_anything(): void
    {
        $hall = Hall::factory()->create();
        $category = PriceCategory::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->set('rows', 3)
            ->set('seatsPerRow', 6)
            ->set('aislesAfter', '3')
            ->set('categoryId', $category->id)
            ->set('doubleLastRow', true)
            ->call('generate')
            ->assertHasNoErrors()
            ->assertReturned(fn (?array $seats): bool => is_array($seats)
                && count($seats) === 2 * 6 + 3
                && max(array_column($seats, 'x')) === 7);

        $this->assertSame(0, Seat::query()->where('hall_id', $hall->id)->count());
    }

    public function test_generator_validates_aisles(): void
    {
        $hall = Hall::factory()->create();
        $category = PriceCategory::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->set('categoryId', $category->id)
            ->set('seatsPerRow', 8)
            ->set('aislesAfter', '4, 8')
            ->call('generate')
            ->assertHasErrors('aislesAfter')
            ->assertHasNoErrors(['categoryId', 'rows', 'seatsPerRow'])
            ->assertReturned(null)
            ->assertSee('Przejście po miejscu 8 jest poza rzędem (1–7).')
            ->set('aislesAfter', 'cztery')
            ->call('generate')
            ->assertHasErrors(['aislesAfter' => 'regex']);
    }

    public function test_generator_is_refused_in_restricted_mode(): void
    {
        $hall = Hall::factory()->withSeats(1, 2)->create();
        Screening::factory()->for($hall)->create();

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->assertSee('Tryb ograniczony.')
            ->call('generate')
            ->assertReturned(null)
            ->assertSet('problem', fn (?string $problem): bool => str_contains((string) $problem, 'historię sprzedaży albo nadchodzące seanse'));
    }

    public function test_save_passes_layout_to_service_and_reports_result(): void
    {
        $hall = Hall::factory()->create();
        $category = PriceCategory::factory()->create();
        $seat = fn (int $x): array => ['id' => null, 'x' => $x, 'y' => 1, 'type' => 'standard', 'category_id' => $category->id, 'active' => true];

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->call('save', [$seat(1), $seat(2), $seat(3)])
            ->assertSet('problem', null)
            ->assertSet('notice', 'Zapisano układ: 3 aktywnych miejsc, siatka 1 × 3.')
            ->assertDispatched('layout-saved');

        $this->assertSame(['A1', 'A2', 'A3'], Seat::query()->where('hall_id', $hall->id)->orderBy('position_x')->get()->map->label->all());
    }

    public function test_invalid_layout_is_listed_in_component(): void
    {
        $hall = Hall::factory()->create();
        $category = PriceCategory::factory()->create();
        $seat = ['id' => null, 'x' => 1, 'y' => 1, 'type' => 'standard', 'category_id' => $category->id, 'active' => true];

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->call('save', [$seat, $seat])
            ->assertSet('problem', 'Układ sali zawiera błędy: 1.')
            ->assertSee('Miejsca nachodzą na siebie w rzędzie siatki 1, kratka 1.')
            ->assertNotDispatched('layout-saved');
    }

    public function test_save_checks_permission_again_on_every_call(): void
    {
        $hall = Hall::factory()->create();
        $category = PriceCategory::factory()->create();
        $staff = User::factory()->staff($hall->cinema)->create();
        $seat = ['id' => null, 'x' => 1, 'y' => 1, 'type' => 'standard', 'category_id' => $category->id, 'active' => true];

        // Strona otwarta przez admina, a zapis przychodzi już w sesji bez uprawnień
        // (np. rola odebrana w trakcie) — mount() nie wystarcza.
        // Po 403 testowy komponent nie ma już migawki — każda akcja na nowej instancji.
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(HallLayoutEditor::class, ['hall' => $hall]);
        $this->actingAs($staff);
        $component->call('save', [$seat])->assertForbidden();

        $component = Livewire::actingAs($admin)->test(HallLayoutEditor::class, ['hall' => $hall]);
        $this->actingAs($staff);
        $component->call('generate')->assertForbidden();

        $this->assertSame(0, Seat::query()->where('hall_id', $hall->id)->count());
    }

    public function test_locked_hall_id_cannot_be_changed_from_the_browser(): void
    {
        $hall = Hall::factory()->create();
        $other = Hall::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())
            ->test(HallLayoutEditor::class, ['hall' => $hall])
            ->set('hallId', $other->id);
    }
}
