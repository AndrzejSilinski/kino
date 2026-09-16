<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Cinemas;

use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Services\Admin\CinemaAdminService;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dodawanie i edycja kina (Etap 7, blok D).
 *
 * #[Locked] na identyfikatorze: publiczne właściwości klient może zmienić
 * w żądaniu Livewire. Bez blokady podmiana cinemaId w przeglądarce
 * zapisałaby formularz do INNEGO kina. save() i tak ładuje kino od nowa
 * i sprawdza Policy na nim — blokada to pierwsza linia, Policy druga.
 */
final class CinemaForm extends Component
{
    #[Locked]
    public ?int $cinemaId = null;

    public string $name = '';

    public string $city = '';

    public string $address = '';

    public string $timezone = 'Europe/Warsaw';

    public function mount(?Cinema $cinema = null): void
    {
        if ($cinema === null || ! $cinema->exists) {
            $this->authorize('create', Cinema::class);

            return;
        }

        $this->authorize('update', $cinema);

        $this->cinemaId = $cinema->id;
        $this->name = $cinema->name;
        $this->city = $cinema->city;
        $this->address = $cinema->address;
        $this->timezone = $cinema->timezone;
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'timezone.timezone' => 'Wybierz strefę czasową z listy (identyfikator IANA, np. Europe/Warsaw).',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nazwa kina',
            'city' => 'miasto',
            'address' => 'adres',
            'timezone' => 'strefa czasowa',
        ];
    }

    public function save(): void
    {
        /** @var array{name: string, city: string, address: string, timezone: string} $data */
        $data = $this->validate();
        $service = app(CinemaAdminService::class);

        try {
            if ($this->cinemaId === null) {
                $this->authorize('create', Cinema::class);
                $cinema = $service->create($data);
                session()->flash('status', "Dodano kino {$cinema->name} (adres: {$cinema->slug}).");
            } else {
                $cinema = Cinema::query()->findOrFail($this->cinemaId);
                $this->authorize('update', $cinema);
                $service->update($cinema, $data);
                session()->flash('status', "Zapisano zmiany w kinie {$data['name']}.");
            }
        } catch (StructureChangeBlockedException $e) {
            $this->addError('timezone', $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.cinemas.index');
    }

    public function render(): View
    {
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::EUROPE);

        if (! in_array($this->timezone, $zones, true)) {
            $zones[] = $this->timezone;
        }

        return view('livewire.admin.cinemas.form', [
            'zones' => $zones,
            'editing' => $this->cinemaId !== null,
        ])->title($this->cinemaId === null ? 'Nowe kino' : 'Edycja kina');
    }
}
