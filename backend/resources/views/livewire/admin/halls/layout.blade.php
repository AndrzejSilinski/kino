{{--
    Edytor układu sali (Etap 7, blok E).
    Część Livewire (ta sekcja): komunikaty, generator, zapis.
    Część Alpine (x-data + wire:ignore niżej): siatka i narzędzia — działa lokalnie,
    a do serwera wysyła cały układ dopiero przy zapisie.
--}}
<section x-data="hallLayoutEditor(@js($config))" @layout-saved.window="loaded($event.detail)">
    <script src="{{ asset('js/admin/hall-layout-editor.js') }}"></script>
    <style>
        .layout-grid { display: grid; gap: 4px; justify-content: start; overflow-x: auto; padding: .5rem 0; }
        .layout-cell { width: 2.2rem; height: 2.2rem; padding: 0; margin: 0; border-radius: 6px; font-size: .62rem; line-height: 1; }
        .layout-empty { border: 1px dashed var(--pico-muted-border-color); background: transparent; }
        .layout-seat { border: 2px solid rgba(0,0,0,.25); color: #fff; font-weight: 600; }
        .layout-seat.is-inactive { opacity: .35; background-image: repeating-linear-gradient(45deg, transparent 0 4px, rgba(0,0,0,.45) 4px 6px); }
        .layout-seat.is-accessible { border-style: dotted; border-color: #000; }
        .layout-screen { text-align: center; letter-spacing: .4em; font-size: .75rem; border-bottom: 3px solid var(--pico-muted-color); margin-bottom: .75rem; }
        .layout-swatch { display: inline-block; width: .9rem; height: .9rem; border-radius: 3px; vertical-align: middle; margin-right: .3rem; }
    </style>

    <hgroup>
        <h1>Układ: {{ $hall->name }}</h1>
        <p><a href="{{ route('admin.cinemas.halls.index', $hall->cinema) }}">&larr; Sale: {{ $hall->cinema->name }}</a></p>
    </hgroup>

    @if ($config['mode'] === 'restricted')
        <article>
            <strong>Tryb ograniczony.</strong> Sala ma historię sprzedaży albo nadchodzące seanse, więc miejsca zachowują
            pozycje, rzędy i numery. Możesz zmieniać kategorię, typ (standard / dla niepełnosprawnych) i dostępność.
        </article>
    @endif

    @if ($notice)
        <article role="status">{{ $notice }}</article>
    @endif
    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">
            {{ $problem }}
            @if ($problems)
                <ul>
                    @foreach ($problems as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
        </article>
    @endif

    @if ($config['mode'] === 'full')
        <details @if (count($config['seats']) === 0) open @endif>
            <summary>Generator układu (zastępuje szkic w edytorze, nic nie zapisuje)</summary>
            <div class="grid">
                <label>Rzędy
                    <input type="number" min="1" max="{{ $config['maxRows'] }}" wire:model="rows" @error('rows') aria-invalid="true" @enderror>
                    @error('rows') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>Miejsca w rzędzie
                    <input type="number" min="1" max="{{ $config['maxColumns'] }}" wire:model="seatsPerRow" @error('seatsPerRow') aria-invalid="true" @enderror>
                    @error('seatsPerRow') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>Przejścia po miejscach
                    <input type="text" placeholder="np. 4, 12" wire:model="aislesAfter" @error('aislesAfter') aria-invalid="true" @enderror>
                    @error('aislesAfter') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>Kategoria
                    <select wire:model="categoryId">
                        @foreach ($categories as $category)
                            <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label><input type="checkbox" wire:model="doubleLastRow"> Ostatni rząd: miejsca podwójne</label>
            <label><input type="checkbox" wire:model="accessibleEdges"> Skrajne miejsca pierwszego rzędu dla osób z niepełnosprawnością</label>
            <button type="button" class="secondary" @click="generate()" wire:loading.attr="disabled">Wygeneruj szkic</button>
        </details>
    @endif

    <div wire:ignore>
        <fieldset>
            <legend>Narzędzie</legend>
            {{-- Jedno radio na wartość: dwa radia "standard" w tej samej grupie (nawet ukryte) odbierałyby sobie zaznaczenie. --}}
            <label><input type="radio" value="standard" x-model="tool"> <span x-text="mode === 'full' ? 'Miejsce standardowe' : 'Zmień na standardowe'"></span></label>
            <label x-show="mode === 'full'"><input type="radio" value="double" x-model="tool"> Miejsce podwójne</label>
            <label><input type="radio" value="accessible" x-model="tool"> Dla osób z niepełnosprawnością</label>
            <label><input type="radio" value="category" x-model="tool"> Maluj kategorię</label>
            <label><input type="radio" value="toggle" x-model="tool"> Wyłącz / włącz miejsce</label>
            <label x-show="mode === 'full'"><input type="radio" value="erase" x-model="tool"> Usuń miejsce (przejście)</label>
        </fieldset>

        <label>Kategoria dla nowych i malowanych miejsc
            <select x-model.number="categoryId">
                <template x-for="category in categories" :key="category.id">
                    <option :value="category.id" x-text="category.name" :selected="category.id === categoryId"></option>
                </template>
            </select>
        </label>

        <p>
            <template x-for="category in categories" :key="'legend-' + category.id">
                <span style="margin-right: 1rem;">
                    <span class="layout-swatch" :style="'background:' + category.color"></span>
                    <span x-text="category.name + ': ' + countIn(category.id)"></span>
                </span>
            </template>
            <strong x-text="'Aktywne miejsca: ' + activeCount"></strong>
        </p>

        <p x-show="hint" x-text="hint" role="status" style="color: var(--pico-del-color);"></p>

        <div class="layout-screen">EKRAN</div>
        <div class="layout-grid" :style="'grid-template-columns: repeat(' + columns + ', 2.2rem)'" data-testid="layout-grid">
            <template x-for="cell in emptyCells" :key="cell.key">
                <button type="button" class="layout-cell layout-empty" :style="'grid-column:' + cell.x + '; grid-row:' + cell.y"
                        :title="'Pusta kratka ' + cell.x + ':' + cell.y" @click="clickEmpty(cell)"></button>
            </template>
            <template x-for="seat in seats" :key="(seat.id ?? 'n') + ':' + seat.x + ':' + seat.y">
                <button type="button" class="layout-cell layout-seat"
                        :class="{ 'is-inactive': !seat.active, 'is-accessible': seat.type === 'accessible' }"
                        :style="'grid-row:' + seat.y + '; grid-column:' + seat.x + ' / span ' + (seat.type === 'double' ? 2 : 1) + '; background-color:' + color(seat) + (seat.type === 'double' ? '; width: auto' : '')"
                        :title="seat.label + (seat.active ? '' : ' (wyłączone)')" :data-label="seat.label"
                        x-text="(seat.type === 'accessible' ? '♿' : '') + seat.label"
                        @click="clickSeat(seat)"></button>
            </template>
        </div>

        <p x-show="mode === 'full'">
            <button type="button" class="secondary outline" @click="extraRows++">+ rząd siatki</button>
            <button type="button" class="secondary outline" @click="extraColumns++">+ kolumna siatki</button>
        </p>

        <button type="button" @click="save()" :aria-busy="saving" :disabled="saving">Zapisz układ</button>
    </div>
</section>
