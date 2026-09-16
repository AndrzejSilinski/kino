<section>
    <style>
        .week-grid td, .week-grid th { vertical-align: top; min-width: 9rem; font-size: .85rem; }
        .week-grid .today { background: var(--pico-mark-background-color); }
        .week-show { display: block; padding: .15rem 0; }
        .week-show.is-cancelled { text-decoration: line-through; opacity: .55; }
        .week-show.is-finished { opacity: .55; }
    </style>

    <hgroup>
        <h1>Repertuar: {{ $cinema->name }}</h1>
        <p>{{ $cinema->city }} · godziny w strefie {{ $cinema->timezone }}</p>
    </hgroup>

    <nav>
        <ul>
            <li><a href="?od={{ $previous }}">&larr; Poprzedni tydzień</a></li>
            <li><a href="?od={{ $today->toDateString() }}">Dziś</a></li>
            <li><a href="?od={{ $next }}">Następny tydzień &rarr;</a></li>
        </ul>
        @if ($canPlan)
            <ul>
                <li><a href="{{ route('admin.cinemas.screenings.copy', ['cinema' => $cinema, 'z' => $days[0]->toDateString()]) }}" role="button" class="secondary">Kopiuj dzień</a></li>
                <li><a href="{{ route('admin.cinemas.screenings.create', $cinema) }}" role="button">Dodaj seans</a></li>
            </ul>
        @endif
    </nav>

    <div class="overflow-auto">
        <table class="week-grid">
            <thead>
                <tr>
                    <th scope="col">Sala</th>
                    @foreach ($days as $day)
                        <th scope="col" @class(['today' => $day->equalTo($today)])>
                            {{ $day->locale('pl')->isoFormat('dd D.MM') }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($halls as $hall)
                    <tr wire:key="hall-{{ $hall->id }}">
                        <th scope="row">
                            {{ $hall->name }}
                            @unless ($hall->is_active) <br><small>wyłączona</small> @endunless
                        </th>
                        @foreach ($days as $day)
                            <td @class(['today' => $day->equalTo($today)])>
                                @foreach ($grid[$hall->id][$day->toDateString()] ?? [] as $screening)
                                    @php($label = $screening->starts_at->setTimezone($cinema->timezone)->format('H:i').' '.$screening->movie->title.' '.$screening->projection_type->label())
                                    <span class="week-show {{ 'is-'.$screening->status->value }}" data-screening="{{ $screening->id }}">
                                        @if ($canPlan)
                                            <a href="{{ route('admin.screenings.edit', $screening) }}">{{ $label }}</a>
                                        @else
                                            {{ $label }}
                                        @endif
                                        <small>· bilety {{ $screening->sold_count }}</small>
                                    </span>
                                @endforeach
                                @if ($canPlan && $hall->is_active && $day->greaterThanOrEqualTo($today))
                                    <a href="{{ route('admin.cinemas.screenings.create', ['cinema' => $cinema, 'sala' => $hall->id, 'data' => $day->toDateString()]) }}" title="Dodaj seans w tej sali tego dnia"><small>+ seans</small></a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="8">Kino nie ma jeszcze sal.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p><small>Przekreślone — odwołane; wyszarzone — zakończone. Kolizje liczone z reklamami i sprzątaniem.</small></p>
</section>
