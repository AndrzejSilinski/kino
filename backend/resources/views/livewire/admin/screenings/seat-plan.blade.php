<section @if ($live) wire:poll.10s @endif>
    <style>
        .plan-grid { display: grid; gap: 4px; justify-content: start; overflow-x: auto; padding: .5rem 0; }
        .plan-seat { width: 2.2rem; height: 2.2rem; border-radius: 6px; font-size: .62rem; font-weight: 600; display: flex; align-items: center; justify-content: center; border: 2px solid transparent; text-decoration: none; }
        .plan-seat.is-free { background: #e8f5e9; color: #1b5e20; border-color: #66bb6a; }
        .plan-seat.is-held { background: #fff3e0; color: #e65100; border-color: #ffa726; }
        .plan-seat.is-sold { background: #ffebee; color: #b71c1c; border-color: #ef5350; }
        .plan-seat.is-unavailable { background: repeating-linear-gradient(45deg, #eee 0 4px, #ccc 4px 6px); color: #777; }
        .plan-screen { text-align: center; letter-spacing: .4em; font-size: .75rem; border-bottom: 3px solid var(--pico-muted-color); margin-bottom: .75rem; }
    </style>

    <hgroup>
        <h1>Plan sali: {{ $screening->movie->title }}</h1>
        <p>
            {{ $screening->hall->cinema->name }}, {{ $screening->hall->name }},
            {{ $screening->starts_at->setTimezone($timezone)->format('Y-m-d H:i') }} ({{ $timezone }})
            · <a href="{{ route('admin.cinemas.screenings.index', ['cinema' => $screening->hall->cinema, 'od' => $screening->starts_at->setTimezone($timezone)->toDateString()]) }}">repertuar</a>
        </p>
    </hgroup>

    <p data-testid="plan-summary">
        Wolne: <strong>{{ $plan['summary']['free'] }}</strong> ·
        zablokowane: <strong>{{ $plan['summary']['held'] }}</strong> ·
        sprzedane: <strong>{{ $plan['summary']['sold'] }}</strong> ·
        niedostępne: <strong>{{ $plan['summary']['unavailable'] }}</strong>
        · <small>{{ $live ? 'odświeżanie co 10 s' : 'seans poza sprzedażą — widok statyczny' }} (wersja stanu {{ $plan['version'] }})</small>
    </p>

    <div class="plan-screen">EKRAN</div>
    <div class="plan-grid" style="grid-template-columns: repeat({{ max(1, $screening->hall->grid_cols) }}, 2.2rem)">
        @foreach ($plan['seats'] as $seat)
            @php($style = 'grid-row:'.$seat['position']['y'].'; grid-column:'.$seat['position']['x'].' / span '.($seat['type'] === 'double' ? 2 : 1).($seat['type'] === 'double' ? '; width:auto' : ''))
            @if ($seat['status'] === 'sold' && isset($references[$seat['id']]))
                <a class="plan-seat is-sold" style="{{ $style }}" href="{{ route('admin.bookings.show', $references[$seat['id']]) }}"
                   title="{{ $seat['label'] }}: sprzedane, rezerwacja {{ $references[$seat['id']] }}" data-status="sold">{{ $seat['label'] }}</a>
            @else
                <span class="plan-seat is-{{ $seat['status'] }}" style="{{ $style }}" title="{{ $seat['label'] }}: {{ ['free' => 'wolne', 'held' => 'zablokowane', 'sold' => 'sprzedane', 'unavailable' => 'niedostępne'][$seat['status']] ?? $seat['status'] }}" data-status="{{ $seat['status'] }}">{{ $seat['label'] }}</span>
            @endif
        @endforeach
    </div>
</section>
