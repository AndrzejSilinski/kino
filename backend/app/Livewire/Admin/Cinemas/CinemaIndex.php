<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Cinemas;

use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Services\Admin\CinemaAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista kin w panelu (Etap 7, blok D): wyszukiwanie, paginacja, włączanie/wyłączanie.
 *
 * KAŻDA publiczna metoda komponentu to endpoint wywoływalny z przeglądarki,
 * a jej argumenty przychodzą od klienta. Dlatego toggleActive() ładuje kino
 * od nowa i sprawdza Policy na TYM rekordzie — nie ufa temu, co pokazał widok.
 */
#[Title('Kina')]
final class CinemaIndex extends Component
{
    use WithPagination;

    /** Filtr w adresie (?q=...): przefiltrowaną listę da się wysłać linkiem. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Cinema::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $cinemaId): void
    {
        $cinema = Cinema::query()->findOrFail($cinemaId);
        $this->authorize('update', $cinema);

        $this->notice = null;
        $this->problem = null;

        try {
            $cinema = app(CinemaAdminService::class)->setActive($cinema, ! $cinema->is_active);
            $this->notice = $cinema->is_active ? "Włączono kino {$cinema->name}." : "Wyłączono kino {$cinema->name}.";
        } catch (StructureChangeBlockedException $e) {
            $this->problem = $e->getMessage();
        }
    }

    public function paginationView(): string
    {
        return 'livewire.admin.partials.pagination';
    }

    public function render(): View
    {
        // Znaki % i _ z wyszukiwarki to dla LIKE symbole wieloznaczne — escapujemy je,
        // żeby wpisane "50%" szukało dosłownie, a nie "wszystkiego zaczynającego się od 50".
        $term = addcslashes(trim($this->search), '%_\\');

        $cinemas = Cinema::query()
            ->withCount('halls')
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $where) => $where
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('city', 'ilike', "%{$term}%"),
            ))
            ->orderBy('city')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.cinemas.index', ['cinemas' => $cinemas]);
    }
}
