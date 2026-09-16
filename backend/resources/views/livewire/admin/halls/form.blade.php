<section>
    <hgroup>
        <h1>{{ $editing ? 'Edycja sali' : 'Nowa sala' }}</h1>
        <p><a href="{{ route('admin.cinemas.halls.index', $cinema) }}">&larr; Sale: {{ $cinema->name }}</a></p>
    </hgroup>

    <form wire:submit="save" novalidate>
        <label>
            Nazwa sali
            <input type="text" wire:model="name" maxlength="60" @error('name') aria-invalid="true" @enderror>
            @error('name') <small role="alert">{{ $message }}</small> @enderror
        </label>

        <fieldset>
            <legend>Typy projekcji obsługiwane przez salę</legend>
            @foreach ($types as $type)
                <label>
                    <input type="checkbox" wire:model="projectionTypes" value="{{ $type->value }}">
                    {{ \App\Support\Labels::projectionType($type) }}
                </label>
            @endforeach
            @error('projectionTypes') <small role="alert">{{ $message }}</small> @enderror
            @error('projectionTypes.*') <small role="alert">{{ $message }}</small> @enderror
        </fieldset>

        <button type="submit" wire:loading.attr="disabled">{{ $editing ? 'Zapisz zmiany' : 'Dodaj salę' }}</button>
    </form>
</section>
