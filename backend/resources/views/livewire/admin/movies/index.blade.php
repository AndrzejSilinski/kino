<section>
    <hgroup>
        <h1>Filmy</h1>
        <p>Filmy całej sieci. Wyłączony film znika z publicznej listy; filmów się nie usuwa (seanse i bilety wskazują film).</p>
    </hgroup>

    @if ($notice)
        <article role="status">{{ $notice }}</article>
    @endif
    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">{{ $problem }}</article>
    @endif

    <div class="grid">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Szukaj po tytule" aria-label="Szukaj filmu">
        <div style="text-align: right;">
            <a href="{{ route('admin.movies.create') }}" role="button">Dodaj film</a>
        </div>
    </div>

    <div class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th scope="col">Plakat</th>
                    <th scope="col">Tytuł</th>
                    <th scope="col">Adres w API</th>
                    <th scope="col">Czas</th>
                    <th scope="col">Wiek</th>
                    <th scope="col">Nadchodzące seanse</th>
                    <th scope="col">Status</th>
                    <th scope="col">Akcje</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movies as $movie)
                    <tr wire:key="movie-{{ $movie->id }}">
                        <td>
                            @if ($movie->poster_path)
                                <img src="{{ Storage::disk('public')->url($movie->poster_path) }}" alt="" width="40" height="60" loading="lazy" style="object-fit: cover;">
                            @else
                                <small>brak</small>
                            @endif
                        </td>
                        <td>
                            {{ $movie->title }}
                            @if ($movie->original_title && $movie->original_title !== $movie->title)
                                <br><small>{{ $movie->original_title }}</small>
                            @endif
                        </td>
                        <td><code>{{ $movie->slug }}</code></td>
                        <td>{{ $movie->duration_minutes }} min</td>
                        <td>{{ $movie->age_rating }}</td>
                        <td>{{ $movie->upcoming_screenings_count }}</td>
                        <td>{{ $movie->is_active ? 'aktywny' : 'wyłączony' }}</td>
                        <td>
                            <a href="{{ route('admin.movies.edit', $movie) }}">Edytuj</a>
                            ·
                            <a href="#" wire:click.prevent="toggleActive({{ $movie->id }})"
                               wire:confirm="{{ $movie->is_active ? 'Wyłączyć film '.$movie->title.'?' : 'Włączyć film '.$movie->title.'?' }}">
                                {{ $movie->is_active ? 'Wyłącz' : 'Włącz' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">Brak filmów spełniających kryteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $movies->links() }}
</section>
