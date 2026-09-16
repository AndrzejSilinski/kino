<section>
    <hgroup>
        <h1>{{ $editing ? 'Edycja kina' : 'Nowe kino' }}</h1>
        <p><a href="{{ route('admin.cinemas.index') }}">&larr; Lista kin</a></p>
    </hgroup>

    <form wire:submit="save" novalidate>
        <label>
            Nazwa kina
            <input type="text" wire:model="name" maxlength="120" @error('name') aria-invalid="true" @enderror>
            @error('name') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <div class="grid">
            <label>
                Miasto
                <input type="text" wire:model="city" maxlength="80" @error('city') aria-invalid="true" @enderror>
                @error('city') <small role="alert">{{ $message }}</small> @enderror
            </label>

            <label>
                Strefa czasowa
                <select wire:model="timezone" @error('timezone') aria-invalid="true" @enderror>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                @error('timezone') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>

        <label>
            Adres
            <input type="text" wire:model="address" maxlength="255" @error('address') aria-invalid="true" @enderror>
            @error('address') <small role="alert">{{ $message }}</small> @enderror
        </label>

        @unless ($editing)
            <small>Adres kina w API (slug) powstanie z miasta i nazwy i nie zmieni się przy późniejszej edycji.</small>
        @endunless

        <button type="submit" wire:loading.attr="disabled">{{ $editing ? 'Zapisz zmiany' : 'Dodaj kino' }}</button>
    </form>
</section>
