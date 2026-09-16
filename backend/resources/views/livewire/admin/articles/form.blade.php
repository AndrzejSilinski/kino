<section>
    <hgroup>
        <h1>{{ $editing ? 'Edycja artykułu' : 'Nowy artykuł' }}</h1>
        <p><a href="{{ route('admin.articles.index') }}">&larr; Aktualności</a>@if ($slug) · adres w API: <code>/api/v1/articles/{{ $slug }}</code> (nie zmienia się po edycji tytułu)@endif</p>
    </hgroup>

    <form wire:submit="save">
        <div class="grid">
            <label>Rodzaj
                <select wire:model.live="type" @error('type') aria-invalid="true" @enderror>
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('type') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>Film {{ $type === 'premiere' ? '(wymagany)' : '(opcjonalnie)' }}
                <select wire:model="movieId" @error('movieId') aria-invalid="true" @enderror>
                    <option value="">— bez filmu —</option>
                    @foreach ($movies as $movie)
                        <option value="{{ $movie->id }}">{{ $movie->title }}{{ $movie->premiere_date ? ' ('.$movie->premiere_date->format('Y-m-d').')' : '' }}</option>
                    @endforeach
                </select>
                @error('movieId') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>

        <label>Tytuł
            <input type="text" wire:model="title" maxlength="200" @error('title') aria-invalid="true" @enderror>
            @error('title') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <label>Zajawka (do 300 znaków, na listę)
            <textarea wire:model="excerpt" rows="2" maxlength="300" @error('excerpt') aria-invalid="true" @enderror></textarea>
            @error('excerpt') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <div class="grid">
            <label>Treść (Markdown; surowy HTML jest usuwany)
                <textarea wire:model.live.debounce.500ms="body" rows="14" @error('body') aria-invalid="true" @enderror></textarea>
                @error('body') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <article data-testid="preview">
                <header><small>Podgląd — tak zobaczą to klienci</small></header>
                {!! $previewHtml !!}
            </article>
        </div>

        <fieldset>
            <legend>Status</legend>
            @foreach ($statuses as $option)
                <label><input type="radio" wire:model="status" value="{{ $option->value }}"> {{ $option->label() }}</label>
            @endforeach
            @error('status') <small role="alert">{{ $message }}</small> @enderror
        </fieldset>

        <div class="grid">
            <label>Data publikacji ({{ $timezone }})
                <input type="date" wire:model="publishDate" @error('publishDate') aria-invalid="true" @enderror>
                @error('publishDate') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>Godzina
                <input type="time" wire:model="publishTime" step="60" @error('publishTime') aria-invalid="true" @enderror>
                @error('publishTime') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>
        <p><small>Opublikowany bez daty ukaże się od razu. Data w przyszłości = artykuł zaplanowany: API pokaże go sam o tej godzinie.</small></p>

        <button type="submit" wire:loading.attr="disabled">{{ $editing ? 'Zapisz zmiany' : 'Dodaj artykuł' }}</button>
    </form>
</section>
