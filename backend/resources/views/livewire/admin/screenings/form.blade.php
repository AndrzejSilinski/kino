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
        <button type="button" class="secondary outline" wire:click="cancelScreening"
                wire:confirm="Odwołać ten seans? Termin w sali się zwolni, a seans zniknie z repertuaru.">
            Odwołaj seans
        </button>
    @endif
</section>
