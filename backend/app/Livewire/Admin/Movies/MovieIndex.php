<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Movies;

use App\Enums\ScreeningStatus;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Movie;
use App\Services\Admin\MovieAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista filmów w panelu (Etap 7, blok F): wyszukiwanie, paginacja, włączanie/wyłączanie.
 *
 * Liczba nadchodzących seansów jest w tabeli, bo to ona decyduje, czy film
 * da się wyłączyć albo zmienić mu czas trwania — administrator widzi powód
 * blokady, zanim kliknie.
 */
#[Title('Filmy')]
final class MovieIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Movie::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $movieId): void
    {
        $movie = Movie::query()->findOrFail($movieId);
        $this->authorize('update', $movie);

        $this->notice = null;
        $this->problem = null;

        try {
            $movie = app(MovieAdminService::class)->setActive($movie, ! $movie->is_active);
            $this->notice = $movie->is_active ? "Włączono film {$movie->title}." : "Wyłączono film {$movie->title}.";
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
        // Znaki % i _ to dla LIKE symbole wieloznaczne — szukamy dosłownie.
        $term = addcslashes(trim($this->search), '%_\\');

        $movies = Movie::query()
            ->withCount(['screenings as upcoming_screenings_count' => fn (Builder $screenings) => $screenings
                ->where('status', ScreeningStatus::Scheduled)
                ->where('ends_at', '>', CarbonImmutable::now())])
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $where) => $where
                    ->where('title', 'ilike', "%{$term}%")
                    ->orWhere('original_title', 'ilike', "%{$term}%"),
            ))
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(20);

        return view('livewire.admin.movies.index', ['movies' => $movies]);
    }
}
