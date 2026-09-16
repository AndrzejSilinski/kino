<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Halls;

use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Models\Hall;
use App\Services\Admin\HallAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Sale jednego kina (Etap 7, blok D). Bez paginacji: kino ma kilka sal.
 *
 * toggleActive() przyjmuje id sali od klienta, więc sprawdza, czy sala
 * należy do TEGO kina — inaczej z listy kina A dałoby się przełączać sale kina B.
 */
final class HallIndex extends Component
{
    #[Locked]
    public int $cinemaId;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Cinema $cinema): void
    {
        $this->authorize('viewAny', Hall::class);

        $this->cinemaId = $cinema->id;
    }

    public function toggleActive(int $hallId): void
    {
        $hall = Hall::query()->where('cinema_id', $this->cinemaId)->findOrFail($hallId);
        $this->authorize('update', $hall);

        $this->notice = null;
        $this->problem = null;

        try {
            $hall = app(HallAdminService::class)->setActive($hall, ! $hall->is_active);
            $this->notice = $hall->is_active ? "Włączono salę {$hall->name}." : "Wyłączono salę {$hall->name}.";
        } catch (StructureChangeBlockedException $e) {
            $this->problem = $e->getMessage();
        }
    }

    public function render(): View
    {
        $cinema = Cinema::query()->findOrFail($this->cinemaId);

        $halls = Hall::query()
            ->where('cinema_id', $cinema->id)
            ->withCount(['seats as active_seats_count' => fn (Builder $seats) => $seats->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return view('livewire.admin.halls.index', ['cinema' => $cinema, 'halls' => $halls])
            ->title('Sale: '.$cinema->name);
    }
}
