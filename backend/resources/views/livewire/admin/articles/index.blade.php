<section>
    <hgroup>
        <h1>Aktualności</h1>
        <p>Artykuły „Aktualności” i „Nadchodzące premiery” dla całej sieci. Godziny w strefie {{ $timezone }}.</p>
    </hgroup>

    @if ($notice)
        <article role="status">{{ $notice }}</article>
    @endif

    <div class="grid">
        <label>Rodzaj
            <select wire:model.live="type">
                <option value="">wszystkie</option>
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>Status
            <select wire:model.live="status">
                <option value="">wszystkie</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>Tytuł
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Szukaj po tytule">
        </label>
        <div style="text-align: right; align-self: end;">
            <a href="{{ route('admin.articles.create') }}" role="button">Nowy artykuł</a>
        </div>
    </div>

    <div class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th scope="col">Tytuł</th>
                    <th scope="col">Rodzaj</th>
                    <th scope="col">Widoczność</th>
                    <th scope="col">Publikacja</th>
                    <th scope="col">Film</th>
                    <th scope="col">Autor</th>
                    <th scope="col">Akcje</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr wire:key="article-{{ $article->id }}">
                        <td>{{ $article->title }}<br><small><code>{{ $article->slug }}</code></small></td>
                        <td>{{ $article->type->label() }}</td>
                        <td>
                            @if ($article->status->value === 'draft')
                                szkic
                            @elseif ($article->isVisibleAt($now))
                                <strong>widoczny</strong>
                            @else
                                zaplanowany
                            @endif
                        </td>
                        <td>{{ $article->published_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>{{ $article->movie?->title ?? '—' }}</td>
                        <td>{{ $article->author?->name ?? '—' }}</td>
                        <td>
                            <a href="{{ route('admin.articles.edit', $article) }}">Edytuj</a>
                            ·
                            <a href="#" wire:click.prevent="deleteArticle({{ $article->id }})"
                               wire:confirm="Usunąć artykuł „{{ $article->title }}”? Tej operacji nie można cofnąć.">Usuń</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Brak artykułów spełniających kryteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $articles->links() }}
</section>
