{{-- Logowanie do panelu (Etap 7, blok B3). Zwykły formularz POST, bez Livewire. --}}
<!DOCTYPE html>
<html lang="pl" data-theme="light">
<head>
    @include('admin.partials.head', ['title' => 'Logowanie'])
</head>
<body>
    <main class="container" style="max-width: 28rem; padding-top: 4rem;">
        <article>
            <header>
                <h1 style="margin-bottom: 0;">Panel kina</h1>
                <small>Dostęp dla administratorów i obsługi kin.</small>
            </header>

            {{-- novalidate: komunikaty pokazuje serwer (FormRequest), a nie przeglądarka. --}}
            <form method="POST" action="{{ route('admin.login.store') }}" novalidate>
                @csrf

                <label for="email">
                    Adres e-mail
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           autocomplete="username" required autofocus
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <small id="email-error" role="alert">{{ $message }}</small>
                    @enderror
                </label>

                <label for="password">
                    Hasło
                    <input id="password" type="password" name="password"
                           autocomplete="current-password" required
                           @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')
                        <small id="password-error" role="alert">{{ $message }}</small>
                    @enderror
                </label>

                <button type="submit">Zaloguj</button>
            </form>
        </article>
    </main>
</body>
</html>
