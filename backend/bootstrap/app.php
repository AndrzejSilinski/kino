<?php

use App\Exceptions\CinemaException;
use App\Http\ApiExceptionRenderer;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // Wersję narzuca framework, zanim plik tras zostanie wczytany,
        // więc nie da się przypadkiem dodać endpointu bez wersji.
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Panel administracyjny (Etap 7). Gość na trasie z 'auth' trafia na
        // formularz panelu. Framework domyślnie kieruje na route('login'),
        // której w projekcie nie ma. Dla /api/* zwracamy null: API nigdy nie
        // przekierowuje, 401 w JSON-ie nadaje ApiExceptionRenderer.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('admin.login'),
        );

        // Zalogowany, który otworzy /admin/login (middleware 'guest'), wraca na pulpit.
        $middleware->redirectUsersTo(fn (): string => route('admin.dashboard'));

        // Autoryzacja kanałów panelu (blok L): ForceJsonResponse musi zadziałać PRZED auth.
        // Kolejność z ->middleware([...]) na trasie nie wystarcza — Laravel sortuje middleware
        // według listy priorytetów i przesuwa auth przed SubstituteBindings z grupy 'web',
        // czyli przed wszystko, co nie jest na tej liście (pułapka BR).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Żądania do /api/* i te z Accept: application/json dostają JSON
        // zamiast HTML-owej strony błędu. Panel Livewire z Etapu 7 pójdzie
        // na /admin/*, więc dalej zobaczy normalne strony.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Wyjątki domenowe NIE są awariami aplikacji — to zaplanowane
        // wyniki operacji: zajęte miejsce, odwołany seans, zły format
        // sesji. Przy premierze poleciałyby setki razy dziennie i utopiły
        // w dzienniku prawdziwe błędy. Do klienta idą jako 409/422,
        // do logu nie trafiają wcale.
        $exceptions->dontReport(CinemaException::class);

        // Pierwszy parametr typowany na Throwable oznacza, że callback
        // dostaje KAŻDY wyjątek. Zwrócenie null = "obsłuż domyślnie".
        $exceptions->render(function (Throwable $e, Request $request) {
            return app(ApiExceptionRenderer::class)($e, $request);
        });
    })->create();
