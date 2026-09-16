<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wymusza odpowiedzi błędów w JSON-ie na trasie spoza /api (Etap 7, blok L).
 *
 * pusher-js wysyła żądanie autoryzacji kanału formularzem, bez Accept: application/json.
 * Na trasie /admin/... bez tego nagłówka Laravel przekierowałby gościa na logowanie,
 * błąd walidacji zamienił w przekierowanie wstecz, a wyjątek domenowy (403 kanału)
 * w stronę błędu 500. Z nagłówkiem działa jedna ścieżka: ApiExceptionRenderer
 * i ten sam kształt błędu co w API (401, 403, 422, 429).
 *
 * Musi stać PRZED auth na liście middleware trasy.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
