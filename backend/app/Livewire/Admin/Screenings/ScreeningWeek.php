<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Screenings;

use App\Enums\TicketStatus;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Siatka repertuaru kina: sale × 7 dni (Etap 7, blok G2; wymóg 2.2 "siatka na tydzień do przodu").
 *
 * DNI W STREFIE KINA: kolumna "2 października" to od 00:00 do 24:00 czasu kina,
 * zamienione na UTC przed zapytaniem (pułapka BN). Seans o 00:30 jest w kolumnie
 * dnia, w którym zobaczy go klient.
 *
 * Administrator planuje i edytuje; obsługa kina widzi siatkę swojego kina bez
 * linków do edycji (CinemaPolicy::viewRepertoire).
 */
final class ScreeningWeek extends Component
{
    #[Locked]
    public int $cinemaId;

    /** Pierwszy dzień widoku (?od=RRRR-MM-DD); pusty = dziś w strefie kina. */
    #[Url(as: 'od', except: '')]
    public string $from = '';

    public function mount(Cinema $cinema): void
    {
        $this->authorize('viewRepertoire', $cinema);

        $this->cinemaId = $cinema->id;
    }

    public function render(): View
    {
        $cinema = Cinema::query()->findOrFail($this->cinemaId);
        $this->authorize('viewRepertoire', $cinema);

        $today = CarbonImmutable::now($cinema->timezone)->startOfDay();
        $start = $this->startDay($cinema->timezone) ?? $today;
        $days = array_map(fn (int $offset): CarbonImmutable => $start->addDays($offset), range(0, 6));

        $halls = Hall::query()->where('cinema_id', $cinema->id)->orderBy('name')->get(['id', 'name', 'is_active', 'projection_types']);

        $screenings = Screening::query()
            ->whereIn('hall_id', $halls->pluck('id'))
            ->where('starts_at', '>=', $start->utc())
            ->where('starts_at', '<', $start->addDays(7)->utc())
            ->with('movie:id,title')
            ->withCount(['tickets as sold_count' => fn (Builder $tickets) => $tickets->where('status', '!=', TicketStatus::Cancelled)])
            ->orderBy('starts_at')
            ->get();

        // [hall_id][Y-m-d] => lista seansów, dzień liczony w strefie kina.
        $grid = [];
        foreach ($screenings as $screening) {
            $grid[$screening->hall_id][$screening->starts_at->setTimezone($cinema->timezone)->toDateString()][] = $screening;
        }

        return view('livewire.admin.screenings.week', [
            'cinema' => $cinema,
            'halls' => $halls,
            'days' => $days,
            'grid' => $grid,
            'today' => $today,
            'previous' => $start->subDays(7)->toDateString(),
            'next' => $start->addDays(7)->toDateString(),
            'canPlan' => auth()->user()->can('create', Screening::class),
        ])->title('Repertuar: '.$cinema->name);
    }

    private function startDay(string $timezone): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->from) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $this->from));

        return checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone)
            : null;
    }
}
