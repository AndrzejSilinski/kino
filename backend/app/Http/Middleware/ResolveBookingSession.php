<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidSessionException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesja zakupowa (koszyk) — identyfikator wydawany przez serwer.
 *
 * Odpowiada na pytanie "czyja jest ta blokada miejsca", gdy użytkownik
 * jeszcze się nie zalogował. Blokady zakładamy PRZED logowaniem: klient
 * wybiera fotele, a konto zakłada dopiero przed płatnością.
 *
 * Przepływ:
 *   1. brak nagłówka  -> serwer losuje nowy identyfikator
 *   2. zły format     -> 422 INVALID_SESSION_ID
 *   3. poprawny       -> używamy przysłanego
 * W każdym z tych przypadków identyfikator wraca w nagłówku ODPOWIEDZI,
 * więc klient zawsze wie, co zapisać po swojej stronie.
 */
class ResolveBookingSession
{
    /** Nagłówek w żądaniu i w odpowiedzi. */
    public const HEADER = 'X-Session-Id';

    /** Klucz w atrybutach requestu — czytają go kontrolery i limiter. */
    public const ATTRIBUTE = 'booking_session_id';

    private const LENGTH = 32;

    private const PATTERN = '/^[A-Za-z0-9]{32}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $this->resolve($request);

        // Atrybuty requestu to właściwe miejsce na dane wyliczone przez
        // middleware. Nie wkładamy tego do inputu, bo wtedy FormRequest
        // potraktowałby to jak dane przysłane przez użytkownika.
        $request->attributes->set(self::ATTRIBUTE, $sessionId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $sessionId);

        return $response;
    }

    /**
     * @throws InvalidSessionException gdy nagłówek ma zły format
     */
    private function resolve(Request $request): string
    {
        $raw = $request->header(self::HEADER);

        if ($raw === null || $raw === '') {
            // Str::random korzysta z random_bytes, czyli ze źródła
            // kryptograficznego. 32 znaki alfanumeryczne to ~190 bitów
            // entropii — praktycznie nie do zgadnięcia.
            return Str::random(self::LENGTH);
        }

        if (preg_match(self::PATTERN, $raw) !== 1) {
            throw new InvalidSessionException();
        }

        return $raw;
    }
}
