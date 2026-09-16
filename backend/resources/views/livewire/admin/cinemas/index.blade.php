<section>
    <hgroup>
        <h1>Kina</h1>
        <p>Kina sieci. Wyłączone kino znika z publicznego API; kina się nie usuwa (historia sprzedaży).</p>
    </hgroup>

    @if ($notice)
        <article role="status">{{ $notice }}</article>
    @endif
    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">{{ $problem }}</article>
    @endif

    <div class="grid">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Szukaj po nazwie lub mieście" aria-label="Szukaj kina">
        <div style="text-align: right;">
            <a href="{{ route('admin.cinemas.create') }}" role="button">Dodaj kino</a>
        </div>
    </div>

    <div class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th scope="col">Miasto</th>
                    <th scope="col">Nazwa</th>
                    <th scope="col">Adres w API</th>
                    <th scope="col">Strefa</th>
                    <th scope="col">Sale</th>
                    <th scope="col">Status</th>
                    <th scope="col">Akcje</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cinemas as $cinema)
                    <tr wire:key="cinema-{{ $cinema->id }}">
                        <td>{{ $cinema->city }}</td>
                        <td>{{ $cinema->name }}</td>
                        <td><code>{{ $cinema->slug }}</code></td>
                        <td>{{ $cinema->timezone }}</td>
                        <td><a href="{{ route('admin.cinemas.halls.index', $cinema) }}">{{ $cinema->halls_count }}</a></td>
                        <td>{{ $cinema->is_active ? 'czynne' : 'wyłączone' }}</td>
                        <td>
                            <a href="{{ route('admin.cinemas.edit', $cinema) }}">Edytuj</a>
                            ·
                            <a href="#" wire:click.prevent="toggleActive({{ $cinema->id }})"
                               wire:confirm="{{ $cinema->is_active ? 'Wyłączyć kino '.$cinema->name.'?' : 'Włączyć kino '.$cinema->name.'?' }}">
                                {{ $cinema->is_active ? 'Wyłącz' : 'Włącz' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Brak kin spełniających kryteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $cinemas->links() }}
</section>
