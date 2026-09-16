{{-- Pulpit (Etap 7, blok L). Komponent Livewire musi mieć JEDEN element główny. --}}
@php($money = fn (int $grosze) => \App\Support\Money::minor($grosze, config('cinema.booking.currency', 'PLN'))->toArray()['formatted'])
@php($timezones = $scope->pluck('timezone', 'id'))
@php($types = ['booking.created' => 'nowa rezerwacja', 'booking.paid' => 'płatność', 'booking.cancelled' => 'anulowanie', 'booking.expired' => 'wygaśnięcie', 'booking.refunded' => 'zwrot'])
<section wire:poll.60s>
    @if ($realtime)
        @push('head')
            <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}" data-auth-endpoint="{{ route('admin.broadcasting.auth') }}">
            <script src="{{ asset('vendor/admin/pusher.min.js') }}"></script>
            <script src="{{ asset('vendor/admin/echo.iife.js') }}"></script>
            <script src="{{ asset('js/admin/realtime.js') }}"></script>
        @endpush
    @endif

    <hgroup>
        <h1>Pulpit</h1>
        <p>
            {{ $isAdmin ? ($scope->count() === 1 ? $scope->first()->name : 'Wszystkie kina sieci') : $scope->first()?->name }}
            · „dziś” to doba w strefie {{ $scope->count() > 1 ? 'każdego kina' : 'kina' }} ·
            <span wire:ignore>Na żywo: <strong data-realtime-status>{{ $realtime ? 'łączenie…' : 'wyłączone (odświeżanie co minutę)' }}</strong></span>
        </p>
    </hgroup>

    @if ($isAdmin)
        <label>Kino
            <select wire:model.live="cinemaId">
                <option value="">wszystkie</option>
                @foreach ($allCinemas as $cinema)
                    <option value="{{ $cinema->id }}">{{ $cinema->city }} — {{ $cinema->name }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <div class="grid" data-testid="today">
        <article><header>Sprzedaż dziś (brutto)</header><strong data-metric="gross">{{ $money($today['gross']) }}</strong><br><small>{{ $today['bookings'] }} rezerwacji</small></article>
        <article><header>Zwroty dziś</header><strong data-metric="refunds">{{ $money($today['refunds']) }}</strong>@if ($today['pending_refunds'] > 0)<br><small>w toku: {{ $today['pending_refunds'] }}</small>@endif</article>
        <article><header>Netto</header><strong data-metric="net">{{ $money($today['net']) }}</strong></article>
        <article><header>Bilety dziś</header><strong data-metric="tickets">{{ $today['tickets'] }}</strong></article>
    </div>

    <article>
        <header><strong>Feed sprzedaży</strong> <small>(najnowsze na górze; wpisy „na żywo” pogrubione)</small></header>
        <div class="overflow-auto"><table>
            <thead><tr><th scope="col">Kiedy</th><th scope="col">Zdarzenie</th><th scope="col">Rezerwacja</th><th scope="col">Seans</th><th scope="col">Miejsca</th><th scope="col">Kwota</th></tr></thead>
            <tbody data-testid="feed">
                @forelse ($feed as $entry)
                    @php($key = $entry['reference'].':'.$entry['status'])
                    @php($live = in_array($key, $liveKeys, true))
                    <tr wire:key="feed-{{ $key }}" @if ($live) data-live="1" style="font-weight: 600;" @endif>
                        <td>{{ \Carbon\CarbonImmutable::parse($entry['occurred_at'])->setTimezone($timezones[$entry['cinema']['id']] ?? 'UTC')->format('H:i:s') }}</td>
                        <td>{{ $types[$entry['type']] ?? $entry['type'] }}</td>
                        <td><a href="{{ route('admin.bookings.show', $entry['reference']) }}"><code>…{{ substr($entry['reference'], -6) }}</code></a></td>
                        <td>{{ $entry['screening']['movie_title'] }}<br><small>{{ $scope->count() > 1 ? $entry['cinema']['name'].', ' : '' }}{{ $entry['screening']['hall_name'] }}, {{ \Carbon\CarbonImmutable::parse($entry['screening']['starts_at'])->format('d.m H:i') }}</small></td>
                        <td>{{ $entry['seats_count'] }}</td>
                        <td>{{ $entry['total']['formatted'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Brak rezerwacji.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </article>
    <article>
        <header><strong>Top {{ \App\Services\Admin\SalesDashboardService::TOP_MOVIES }} filmów — ostatnie {{ \App\Services\Admin\SalesDashboardService::TOP_MOVIES_DAYS }} dni</strong></header>
        <ol data-testid="top-movies">
            @forelse ($topMovies as $movie)
                <li>{{ $movie['title'] }} — {{ $movie['tickets'] }} bil., {{ $money($movie['revenue']) }}</li>
            @empty
                <li>Brak sprzedaży w tym okresie.</li>
            @endforelse
        </ol>
    </article>

    <article>
        <header><strong>Obłożenie dzisiejszych seansów</strong></header>
        <table>
            <thead><tr><th scope="col">Godz.</th><th scope="col">Film</th><th scope="col">Sala</th><th scope="col">Bilety</th></tr></thead>
            <tbody>
                @forelse ($occupancy as $row)
                    <tr wire:key="occ-{{ $row['id'] }}">
                        <td>{{ $row['time'] }}</td>
                        <td>{{ $row['movie'] }}</td>
                        <td>{{ $scope->count() > 1 ? $row['cinema'].', ' : '' }}{{ $row['hall'] }}</td>
                        <td>
                            <a href="{{ route('admin.screenings.seats', $row['id']) }}">{{ $row['sold'] }}/{{ $row['capacity'] }}</a>
                            <progress value="{{ $row['sold'] }}" max="{{ max(1, $row['capacity']) }}" aria-label="obłożenie {{ $row['percent'] }}%"></progress>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">Dziś nie ma seansów.</td></tr>
                @endforelse
            </tbody>
        </table>
    </article>
</section>
