{{-- Paginacja komponentów panelu w stylu Pico (domyślne widoki Livewire zakładają Tailwind). --}}
@if ($paginator->hasPages())
    <nav aria-label="Stronicowanie">
        <ul>
            <li>
                <button type="button" class="secondary outline" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                        wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>&larr; Poprzednia</button>
            </li>
            <li><small>Strona {{ $paginator->currentPage() }} z {{ $paginator->lastPage() }} ({{ $paginator->total() }})</small></li>
            <li>
                <button type="button" class="secondary outline" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                        wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages())>Następna &rarr;</button>
            </li>
        </ul>
    </nav>
@endif
