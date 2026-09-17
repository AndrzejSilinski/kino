<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureRateLimiting();
    }

    /**
     * Rygor Eloquenta poza produkcją.
     *
     * preventLazyLoading rzuca wyjątkiem, gdy kod sięga po relację,
     * której nie załadowano przez with(). Dzięki temu problem N+1
     * objawia się jako błąd w teście, a nie 300 zapytań na produkcji.
     * Krytyczne przy planie sali: pętla po ~200 miejscach.
     */
    private function configureModels(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    /**
     * Nazwane limitery. Przypinamy je do tras w routes/api.php przez
     * middleware throttle:<nazwa>. Formatu odpowiedzi 429 tu nie ma —
     * przechwyci go wspólny ApiExceptionRenderer z kroku 3.2.
     */
    private function configureRateLimiting(): void
    {
        // Globalny sufit dla całego API — chroni przed zapętlonym
        // klientem, nie ogranicza normalnego przeglądania repertuaru.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // Logowanie: dwa limity naraz, oba muszą przepuścić.
        // Per e-mail — zgadywanie hasła do jednego konta.
        // Per IP — password spraying po wielu kontach z jednej maszyny.
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        // Rejestracja: masowe zakładanie kont zapycha tabelę users.
        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perHour(10)->by('register:'.$request->ip());
        });

        // Zmiany na koncie (Etap 8, blok I): per użytkownik, nie per IP — trasy wymagają tokenu.
        RateLimiter::for('account', function (Request $request): Limit {
            return Limit::perMinute(30)->by('account:'.$request->user()?->id);
        });

        // Zmiana hasła: pole current_password to wyrocznia hasła dla posiadacza tokenu.
        // 5 prób na 10 minut nie przeszkadza człowiekowi, a zatrzymuje zgadywanie.
        RateLimiter::for('password-change', function (Request $request): Limit {
            return Limit::perMinutes(10, 5)->by('password-change:'.$request->user()?->id);
        });

        // Blokowanie miejsc: kluczem jest SESJA ZAKUPOWA, nie IP.
        // Klienci w galerii dzielą jedno publiczne IP za NAT-em,
        // więc limit per IP odciąłby całą salę podczas premiery.
        RateLimiter::for('seat-locks', function (Request $request): Limit {
            $session = $request->attributes->get('booking_session_id')
                ?? $request->header('X-Session-Id')
                ?? $request->ip();

            return Limit::perMinute(30)->by('seat-locks:'.$session);
        });
    }
}
