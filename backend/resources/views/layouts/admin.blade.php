{{--
    Layout stron Livewire panelu: config livewire.component_layout = layouts::admin.
    Livewire przekazuje tu treść komponentu jako $slot i tytuł z #[Title] jako $title.
    Skrypt Livewire (z Alpine) wstrzykuje się sam: inject_assets = true.
--}}
<!DOCTYPE html>
<html lang="pl" data-theme="light">
<head>
    @include('admin.partials.head')
</head>
<body>
    {{-- loadMissing: jawne zapytanie o kino obsługi, bez leniwego ładowania (decyzja 30). --}}
    @php($panelUser = auth()->user()->loadMissing('cinema'))
    <header class="container">
        <nav>
            <ul>
                <li><strong>Kino · panel</strong></li>
                <li><a href="{{ route('admin.dashboard') }}">Pulpit</a></li>
            </ul>
            <ul>
                <li>
                    <small>
                        {{ $panelUser->name }}
                        ({{ $panelUser->isAdmin() ? 'administrator' : 'obsługa: '.$panelUser->cinema?->name }})
                    </small>
                </li>
                <li>
                    <form method="POST" action="{{ route('admin.logout') }}" style="margin: 0;">
                        @csrf
                        <button type="submit" class="secondary outline">Wyloguj</button>
                    </form>
                </li>
            </ul>
        </nav>
    </header>
    <main class="container">
        {{ $slot }}
    </main>
</body>
</html>
