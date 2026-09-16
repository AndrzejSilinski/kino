<section>
    <hgroup>
        <h1>Kopiowanie repertuaru</h1>
        <p>
            <a href="{{ route('admin.cinemas.screenings.index', ['cinema' => $cinema, 'od' => $sourceDate]) }}">&larr; Repertuar: {{ $cinema->name }}</a>
            · godziny w strefie {{ $cinema->timezone }}
        </p>
    </hgroup>

    <p>
        Kopiujemy seanse zaplanowane i zakończone (bez odwołanych) z cennikami, zachowując godziny w kinie.
        Jeśli choć jeden seans nie zmieści się w dniu docelowym, <strong>nie zostanie skopiowany żaden</strong>.
        Seanse, które już tam są, pomijamy.
    </p>

    @if ($problem)
        <article role="alert" style="border-left: 4px solid var(--pico-del-color);">{{ $problem }}</article>
    @endif

    <form wire:submit="preview" novalidate>
        <div class="grid">
            <label>
                Z dnia
                <input type="date" wire:model.live="sourceDate" @error('sourceDate') aria-invalid="true" @enderror>
                @error('sourceDate') <small role="alert">{{ $message }}</small> @enderror
            </label>
            <label>
                Na dzień
                <input type="date" wire:model.live="targetDate" @error('targetDate') aria-invalid="true" @enderror>
                @error('targetDate') <small role="alert">{{ $message }}</small> @enderror
            </label>
        </div>
        <button type="submit" class="secondary" wire:loading.attr="disabled">Podgląd</button>
    </form>

    @if ($report)
        <article data-testid="copy-report">
            <header>
                Do utworzenia: <strong>{{ $report['created'] }}</strong> ·
                już istnieje: <strong>{{ $report['existing'] }}</strong> ·
                problemy: <strong>{{ count(array_filter($report['items'], fn ($i) => $i['status'] === 'problem')) }}</strong>
            </header>
            <div class="overflow-auto">
                <table>
                    <thead>
                        <tr><th scope="col">Sala</th><th scope="col">Godzina</th><th scope="col">Film</th><th scope="col">Wynik</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['items'] as $item)
                            <tr>
                                <td>{{ $item['hall'] }}</td>
                                <td>{{ $item['time'] }}</td>
                                <td>{{ $item['movie'] }}</td>
                                <td>
                                    @switch($item['status'])
                                        @case('create') skopiuje się @break
                                        @case('exists') już jest — pominięty @break
                                        @default <strong>problem:</strong> {{ $item['message'] }}
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <footer>
                <button type="button" wire:click="copy" wire:loading.attr="disabled" @disabled(! $canCopy)>
                    Kopiuj {{ $report['created'] }} seans(ów)
                </button>
            </footer>
        </article>
    @endif
</section>
