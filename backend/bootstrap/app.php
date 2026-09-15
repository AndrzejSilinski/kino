<?php

use App\Exceptions\CinemaException;
use App\Http\ApiExceptionRenderer;
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
        //
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
