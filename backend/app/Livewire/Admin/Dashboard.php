<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\SalesDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pulpit panelu (Etap 7, blok L): sprzedaż dziś, obłożenie dzisiejszych seansów,
 * top filmów z 7 dni i feed sprzedaży na żywo (wymogi 1.3 i 2.3).
 *
 * FEED NA ŻYWO: Livewire subskrybuje przez Echo kanał private-sales (administrator)
 * albo private-cinemas.{id}.sales (obsługa). Podpis kanału wydaje
 * POST /admin/broadcasting/auth — sesja panelu i CSRF zamiast tokenu Sanctum,
 * decyzja w tej samej ChannelAuthorizationService co w API.
 *
 * ZDARZENIE Z PRZEGLĄDARKI TO TYLKO SYGNAŁ. Argument metody nasłuchującej przysyła
 * klient, więc mógłby go podrobić. Bierzemy z niego numer rezerwacji i status,
 * a treść wpisu czytamy z bazy w zakresie zalogowanego użytkownika.
 *
 * REZERWA: wire:poll.60s odświeża liczby, gdy WebSocket nie działa. Po odzyskaniu
 * połączenia skrypt realtime.js wysyła realtime-reconnected — feed jest wtedy
 * odtwarzany z bazy, bo zdarzenia z przerwy przepadły.
 *
 * authorize() w mount() i w każdej akcji, mimo middleware can:panel.access na trasie.
 */
#[Title('Pulpit')]
final class Dashboard extends Component
{
    /** Najwięcej wpisów feedu trzymanych w komponencie. */
    public const FEED_LIMIT = 20;

    /** Filtr kina — tylko administrator; obsługę zawęża serwis. */
    #[Url(as: 'kino', except: '')]
    public string $cinemaId = '';

    /** @var list<array<string, mixed>> wpisy feedu, najnowsze pierwsze */
    #[Locked]
    public array $feed = [];

    /** @var list<string> klucze wpisów, które przyszły na żywo (podświetlenie) */
    #[Locked]
    public array $liveKeys = [];

    public function mount(SalesDashboardService $dashboard): void
    {
        $this->authorize('panel.access');
        $this->loadFeed($dashboard);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $channel = $user->isAdmin() ? 'sales' : 'cinemas.'.(int) $user->cinema_id.'.sales';

        return [
            // Kropka: nazwa z broadcastAs(), bez przestrzeni nazw App\Events (Etap 6).
            'echo-private:'.$channel.',.sales.activity' => 'onSalesActivity',
            'realtime-reconnected' => 'resync',
        ];
    }

    /** @param array<string, mixed> $event */
    public function onSalesActivity(SalesDashboardService $dashboard, array $event = []): void
    {
        $this->authorize('panel.access');

        $entry = $dashboard->activityEntry(
            $this->cinemas($dashboard),
            is_string($event['reference'] ?? null) ? $event['reference'] : '',
            is_string($event['status'] ?? null) ? $event['status'] : '',
            CarbonImmutable::now(),
        );

        if ($entry === null) {
            return;
        }

        $key = $entry['reference'].':'.$entry['status'];
        $this->feed = array_slice([$entry, ...array_values(array_filter(
            $this->feed,
            fn (array $old): bool => $key !== $old['reference'].':'.$old['status'],
        ))], 0, self::FEED_LIMIT);
        $this->liveKeys = array_slice(array_values(array_unique([$key, ...$this->liveKeys])), 0, self::FEED_LIMIT);
    }

    public function resync(SalesDashboardService $dashboard): void
    {
        $this->authorize('panel.access');
        $this->loadFeed($dashboard);
    }

    public function updatedCinemaId(SalesDashboardService $dashboard): void
    {
        $this->loadFeed($dashboard);
    }

    public function render(): View
    {
        $this->authorize('panel.access');

        $dashboard = app(SalesDashboardService::class);

        /** @var User $user */
        $user = auth()->user();
        $cinemas = $this->cinemas($dashboard);
        $now = CarbonImmutable::now();
        $realtime = config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'));

        return view('livewire.admin.dashboard', [
            'isAdmin' => $user->isAdmin(),
            'allCinemas' => $user->isAdmin() ? $dashboard->cinemasFor($user) : collect(),
            'scope' => $cinemas,
            'today' => $dashboard->today($cinemas, $now),
            'occupancy' => $dashboard->occupancyToday($cinemas, $now),
            'topMovies' => $dashboard->topMovies($cinemas, $now),
            'realtime' => $realtime,
        ]);
    }

    private function loadFeed(SalesDashboardService $dashboard): void
    {
        $this->feed = $dashboard->recentActivity($this->cinemas($dashboard));
        $this->liveKeys = [];
    }

    private function cinemas(SalesDashboardService $dashboard): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return $dashboard->cinemasFor($user, ctype_digit($this->cinemaId) ? (int) $this->cinemaId : null);
    }
}
