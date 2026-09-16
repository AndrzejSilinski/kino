<section>
    <hgroup>
        <h1>{{ $editing ? 'Edycja filmu' : 'Nowy film' }}</h1>
        <p><a href="{{ route('admin.movies.index') }}">&larr; Lista filmów</a></p>
    </hgroup>

    <form wire:submit="save" novalidate>
        <div class="grid">
            <label>
                Tytuł
                <input type="text" wire:model="title" maxlength="200" @error('title') aria-invalid="true" @enderror>
                @error('title') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>
                Tytuł oryginalny
                <input type="text" wire:model="originalTitle" maxlength="200" @error('originalTitle') aria-invalid="true" @enderror>
                @error('originalTitle') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>

        <label>
            Opis
            <textarea wire:model="description" rows="5" maxlength="5000" @error('description') aria-invalid="true" @enderror></textarea>
            @error('description') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <div class="grid">
            <label>
                Czas trwania (minuty, bez reklam)
                <input type="number" min="1" max="600" wire:model="durationMinutes" @error('durationMinutes') aria-invalid="true" @enderror>
                @error('durationMinutes') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>
                Kategoria wiekowa
                <select wire:model="ageRating" @error('ageRating') aria-invalid="true" @enderror>
                    <option value="">— wybierz —</option>
                    @foreach ($ageRatings as $rating)
                        <option value="{{ $rating }}">{{ $rating }}</option>
                    @endforeach
                </select>
                @error('ageRating') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>
                Data premiery
                <input type="date" wire:model="premiereDate" @error('premiereDate') aria-invalid="true" @enderror>
                @error('premiereDate') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>

        <label>
            Gatunki (po przecinku)
            <input type="text" wire:model="genres" maxlength="200" placeholder="np. Sci-Fi, Thriller" @error('genres') aria-invalid="true" @enderror>
            @error('genres') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <fieldset>
            <legend>Plakat</legend>
            <div class="grid">
                <div>
                    <input type="file" wire:model="poster" accept="image/jpeg,image/png" @error('poster') aria-invalid="true" @enderror>
                    <small wire:loading wire:target="poster">Wgrywanie…</small>
                    @error('poster') <small role="alert">{{ $message }}</small> @enderror
                    <small>JPG lub PNG, do 5 MB, co najmniej 300 × 450 px. Zapisujemy JPEG najwyżej 800 × 1200 px.</small>
                    @if ($currentPosterUrl && ! $previewable)
                        <label>
                            <input type="checkbox" wire:model="removePoster">
                            Usuń obecny plakat
                        </label>
                    @endif
                </div>
                <div>
                    @if ($previewable)
                        <figure>
                            <img src="{{ $poster->temporaryUrl() }}" alt="Podgląd nowego plakatu" style="max-height: 240px;">
                            <figcaption><small>Nowy plakat (podgląd)</small></figcaption>
                        </figure>
                    @elseif ($currentPosterUrl)
                        <figure>
                            <img src="{{ $currentPosterUrl }}" alt="Obecny plakat" style="max-height: 240px;">
                            <figcaption><small>Obecny plakat</small></figcaption>
                        </figure>
                    @endif
                </div>
            </div>
        </fieldset>

        @unless ($editing)
            <small>Adres filmu w API (slug) powstanie z tytułu i nie zmieni się przy późniejszej edycji.</small>
        @endunless

        <button type="submit" wire:loading.attr="disabled" wire:target="save, poster">{{ $editing ? 'Zapisz zmiany' : 'Dodaj film' }}</button>
    </form>
</section>
