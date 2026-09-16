<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Halls;

use App\Enums\ProjectionType;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Models\Hall;
use App\Services\Admin\HallAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dodawanie i edycja sali (Etap 7, blok D). Trasa tworzenia zna kino,
 * trasa edycji zna salę — kino wynika z sali, nie z formularza.
 */
final class HallForm extends Component
{
    #[Locked]
    public int $cinemaId;

    #[Locked]
    public ?int $hallId = null;

    public string $name = '';

    /** @var list<string> */
    public array $projectionTypes = [ProjectionType::TwoD->value];

    public function mount(?Cinema $cinema = null, ?Hall $hall = null): void
    {
        if ($hall !== null && $hall->exists) {
            $this->authorize('update', $hall);

            $this->hallId = $hall->id;
            $this->cinemaId = $hall->cinema_id;
            $this->name = $hall->name;
            $this->projectionTypes = array_values($hall->projection_types ?? []);

            return;
        }

        abort_if($cinema === null || ! $cinema->exists, 404);
        $this->authorize('create', Hall::class);

        $this->cinemaId = $cinema->id;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('halls', 'name')->where('cinema_id', $this->cinemaId)->ignore($this->hallId),
            ],
            'projectionTypes' => ['required', 'array', 'min:1'],
            'projectionTypes.*' => ['distinct', Rule::enum(ProjectionType::class)],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.unique' => 'Sala o tej nazwie już istnieje w tym kinie.',
            'projectionTypes.required' => 'Zaznacz co najmniej jeden typ projekcji.',
            'projectionTypes.min' => 'Zaznacz co najmniej jeden typ projekcji.',
            'projectionTypes.*.enum' => 'Nieznany typ projekcji.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['name' => 'nazwa sali', 'projectionTypes' => 'typy projekcji'];
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            // Kolejność jak w enumie, bez duplikatów — niezależnie od kolejności kliknięć.
            'projection_types' => array_values(array_filter(
                array_map(static fn (ProjectionType $type): string => $type->value, ProjectionType::cases()),
                fn (string $value): bool => in_array($value, $this->projectionTypes, true),
            )),
        ];

        $service = app(HallAdminService::class);

        try {
            if ($this->hallId === null) {
                $cinema = Cinema::query()->findOrFail($this->cinemaId);
                $this->authorize('create', Hall::class);
                $hall = $service->create($cinema, $data);
                session()->flash('status', "Dodano salę {$hall->name}. Układ miejsc zdefiniujesz w edytorze sali.");
            } else {
                $hall = Hall::query()->findOrFail($this->hallId);
                $this->authorize('update', $hall);
                $service->update($hall, $data);
                session()->flash('status', "Zapisano zmiany w sali {$data['name']}.");
            }
        } catch (StructureChangeBlockedException $e) {
            $this->addError($e->errorCode() === 'HALL_NAME_TAKEN' ? 'name' : 'projectionTypes', $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.cinemas.halls.index', ['cinema' => Cinema::query()->findOrFail($this->cinemaId)]);
    }

    public function render(): View
    {
        return view('livewire.admin.halls.form', [
            'cinema' => Cinema::query()->findOrFail($this->cinemaId),
            'types' => ProjectionType::cases(),
            'editing' => $this->hallId !== null,
        ])->title($this->hallId === null ? 'Nowa sala' : 'Edycja sali');
    }
}
