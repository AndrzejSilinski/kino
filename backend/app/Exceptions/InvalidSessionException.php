<?php

namespace App\Exceptions;

/**
 * Klient przysłał nagłówek X-Session-Id w złym formacie.
 *
 * Nie wydajemy po cichu nowego identyfikatora, bo klient z uszkodzoną
 * pamięcią lokalną gubiłby w nieskończoność swoje blokady, nie wiedząc
 * dlaczego. Jawny błąd 422 mówi frontendowi: wyczyść i zacznij od nowa.
 */
class InvalidSessionException extends CinemaException
{
    public function __construct()
    {
        parent::__construct('Nieprawidłowy identyfikator sesji zakupowej.');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'INVALID_SESSION_ID';
    }
}
