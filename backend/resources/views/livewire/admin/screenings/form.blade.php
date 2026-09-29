<section>
    <hgroup>
        <h1>{{ $screening ? 'Edycja seansu' : 'Nowy seans' }}</h1>
        <p>
            <a href="{{ route('admin.cinemas.screenings.index', ['cinema' => $cinema, 'od' => $date]) }}">&larr; Repertuar: {{ $cinema->name }}</a>
            · godziny w strefie {{ $cinema->timezone }}
        </p>
    </hgroup>

    @if ($lockedReason)
        <article role="status"><strong>Zmiany zablokowane.</strong> {{ $lockedReason }}</article>
    @endif
    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">{{ $problem }}</article>
    @endif

    <form wire:submit="save" novalidate>
        <fieldset @disabled($lockedReason)>
            <div class="grid">
                <label>
                    Sala
                    <select wire:model.live="hallId" @error('hallId') aria-invalid="true" @enderror>
                        <option value="">— wybierz —</option>
                        @foreach ($halls as $hall)
                            <option value="{{ $hall->id }}" @disabled(! $hall->is_active && (string) $hall->id !== $hallId)>{{ $hall->name }}{{ $hall->is_active ? '' : ' (wyłączona)' }}</option>
                        @endforeach
                    </select>
                    @error('hallId') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>
                    Film
                    <select wire:model.live="movieId" @error('movieId') aria-invalid="true" @enderror>
                        <option value="">— wybierz —</option>
                        @foreach ($movies as $movie)
                            <option value="{{ $movie->id }}">{{ $movie->title }} ({{ $movie->duration_minutes }} min)</option>
                        @endforeach
                    </select>
                    @error('movieId') <small role="alert">{{ $message }}</small> @enderror
                </label>
            </div>

            <div class="grid">
                <label>
                    Data
                    <input type="date" wire:model.live.debounce.500ms="date" @error('date') aria-invalid="true" @enderror>
                    @error('date') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>
                    Godzina rozpoczęcia (z reklamami)
                    <input type="time" wire:model.live.debounce.500ms="time" step="300" @error('time') aria-invalid="true" @enderror>
                    @error('time') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>
                    Projekcja
                    <select wire:model="projectionType" @error('projectionType') aria-invalid="true" @enderror>
                        @foreach ($projectionTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('projectionType') <small role="alert">{{ $message }}</small> @enderror
                </label>
                <label>
                    Wersja
                    <select wire:model="languageVersion" @error('languageVersion') aria-invalid="true" @enderror>
                        @foreach ($languages as $language)
                            <option value="{{ $language->value }}">{{ $language->label() }}</option>
                        @endforeach
                    </select>
                    @error('languageVersion') <small role="alert">{{ $message }}</small> @enderror
                </label>
            </div>

            @if ($preview)
                <p data-testid="timeline-preview"><small>{{ $preview }}</small></p>
            @endif

            <fieldset>
                <legend>Cennik (zł)</legend>
                @error('prices') <small role="alert">{{ $message }}</small> @enderror
                @forelse ($categories as $category)
                    <label wire:key="price-{{ $category->id }}">
                        <span style="display: inline-block; width: .8rem; height: .8rem; border-radius: 3px; background: {{ $category->color }};"></span>
                        {{ $category->name }}
                        <input type="text" inputmode="decimal" wire:model="prices.{{ $category->id }}" placeholder="np. 25,00" @error('prices.'.$category->id) aria-invalid="true" @enderror>
                        @error('prices.'.$category->id) <small role="alert">{{ $message }}</small> @enderror
                    </label>
                @empty
                    <p><small>Wybierz salę z aktywnymi miejscami.</small></p>
                @endforelse
            </fieldset>

            <button type="submit" wire:loading.attr="disabled">{{ $screening ? 'Zapisz zmiany' : 'Dodaj seans' }}</button>
        </fieldset>
    </form>

    @if ($canCancel)
        <hr>

        @if ($cancelReport)
            <article role="alert"><strong>{{ $cancelReport }}</strong></article>
        @endif

        @if ($confirmingMassCancel)
            {{--
                Potwierdzenie z LICZBAMI (decyzja 347). Przeglądarkowe „na pewno?" nie potrafi
                powiedzieć, ilu klientów dostanie powiadomienie ani ile pieniędzy wróci —
                a to jedyna akcja w panelu, która jednym kliknięciem anuluje cudze zakupy.
            --}}
            <article>
                <header><strong>Odwołać seans razem z rezerwacjami?</strong></header>

                @if ($sales['bookings'] > 0)
                    <p>
                        Anulowanych zostanie <strong>{{ $sales['bookings'] }}</strong>
                        {{ $sales['bookings'] === 1 ? 'rezerwacja' : 'rezerwacji' }},
                        a klienci dostaną maila i powiadomienie push.
                        Do zwrotu: <strong>{{ $salesMoney }}</strong>.
                    </p>
                    <p><small>
                        Zwroty rozlicza zadanie w tle (przebiega co pięć minut) — w panelu
                        rezerwacji zobaczysz je najpierw jako „zwrot w toku".
                    </small></p>
                @else
                    <p>Ten seans nie ma rezerwacji. Termin w sali po prostu się zwolni.</p>
                @endif

                <label>
                    Powód anulowania (zapisujemy go przy każdej rezerwacji, klient go nie widzi)
                    <input type="text" wire:model="cancelReason" maxlength="500"
                           placeholder="np. awaria projektora w sali A">
                    @error('cancelReason') <small role="alert">{{ $message }}</small> @enderror
                </label>

                <footer>
                    <button type="button" wire:click="cancelScreeningWithBookings"
                            wire:loading.attr="disabled" wire:target="cancelScreeningWithBookings">
                        <span wire:loading.remove wire:target="cancelScreeningWithBookings">Tak, odwołaj seans</span>
                        <span wire:loading wire:target="cancelScreeningWithBookings">Odwołuję…</span>
                    </button>
                    <button type="button" class="secondary outline" wire:click="dismissMassCancel">
                        Nie, wróć
                    </button>
                </footer>
            </article>
        @else
            <button type="button" class="secondary outline" wire:click="askMassCancel">
                Odwołaj seans
            </button>
        @endif
    @endif
</section>
