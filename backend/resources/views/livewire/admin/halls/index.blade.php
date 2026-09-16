<section>
    <hgroup>
        <h1>Sale: {{ $cinema->name }}</h1>
        <p><a href="{{ route('admin.cinemas.index') }}">&larr; Lista kin</a> · {{ $cinema->city }}</p>
    </hgroup>

    @if ($notice)
        <article role="status">{{ $notice }}</article>
    @endif
    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">{{ $problem }}</article>
    @endif

    <p><a href="{{ route('admin.cinemas.halls.create', $cinema) }}" role="button">Dodaj salę</a></p>

    <div class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th scope="col">Nazwa</th>
                    <th scope="col">Projekcje</th>
                    <th scope="col">Siatka</th>
                    <th scope="col">Aktywne miejsca</th>
                    <th scope="col">Status</th>
                    <th scope="col">Akcje</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($halls as $hall)
                    <tr wire:key="hall-{{ $hall->id }}">
                        <td>{{ $hall->name }}</td>
                        <td>{{ collect($hall->projection_types)->map(fn (string $type) => \App\Support\Labels::projectionType(\App\Enums\ProjectionType::from($type)))->implode(', ') }}</td>
                        <td>{{ $hall->grid_rows }} × {{ $hall->grid_cols }}</td>
                        <td>{{ $hall->active_seats_count }}</td>
                        <td>{{ $hall->is_active ? 'czynna' : 'wyłączona' }}</td>
                        <td>
                            <a href="{{ route('admin.halls.edit', $hall) }}">Edytuj</a>
                            ·
                            <a href="#" wire:click.prevent="toggleActive({{ $hall->id }})"
                               wire:confirm="{{ $hall->is_active ? 'Wyłączyć salę '.$hall->name.'?' : 'Włączyć salę '.$hall->name.'?' }}">
                                {{ $hall->is_active ? 'Wyłącz' : 'Włącz' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">To kino nie ma jeszcze sal.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
