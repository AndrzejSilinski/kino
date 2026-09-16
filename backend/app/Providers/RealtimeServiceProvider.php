<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ResolveBookingSession;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * WebSocket i aktualizacje na żywo (Etap 6).
 *
 * Osobny provider, jak TicketServiceProvider w Etapie 5: limitery i usługi
 * jednego obszaru leżą razem, a AppServiceProvider nie puchnie.
 */
final class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Autoryzacja kanałów. Klient woła ją przy każdej subskrypcji
         * i przy każdym ponownym połączeniu, więc limit musi przetrwać
         * burzę reconnectów po chwilowej awarii sieci.
         *
         * Dwa limity, oba muszą przepuścić (jak przy logowaniu):
         *   60/min per klient — zalogowany: id; anonim: sesja zakupowa
         *     (klienci za wspólnym NAT-em w galerii, decyzja 28); bez obu: IP,
         *   1200/min per IP — sufit na skrypt, który losuje sobie nowe
         *     X-Session-Id przy każdym żądaniu. Wysoki, bo cała sala kinowa
         *     za jednym NAT-em też musi się zmieścić (ok. 2 kanały na osobę).
         */
        RateLimiter::for('broadcasting-auth', static function (Request $request): array {
            $user = $request->user('sanctum');
            $session = (string) $request->header(ResolveBookingSession::HEADER, '');

            $client = match (true) {
                $user !== null => 'user:'.$user->getAuthIdentifier(),
                preg_match('/\A[A-Za-z0-9]{32}\z/', $session) === 1 => 'session:'.$session,
                default => 'ip:'.$request->ip(),
            };

            return [
                Limit::perMinute(60)->by('broadcasting-auth:'.$client),
                Limit::perMinute(1200)->by('broadcasting-auth-ip:'.$request->ip()),
            ];
        });

        /*
         * Autoryzacja kanałów panelu (Etap 7, blok L): trasa za auth, więc zawsze
         * jest użytkownik z sesji (guard web). Pulpit subskrybuje jeden kanał;
         * 30/min wystarcza z zapasem na reconnecty i kilka otwartych kart.
         * Klucz inny niż w API — limity panelu i aplikacji klienta się nie mieszają.
         */
        RateLimiter::for('panel-broadcasting-auth', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('panel-broadcasting-auth:'.$request->user('web')?->getAuthIdentifier()));
    }
}
