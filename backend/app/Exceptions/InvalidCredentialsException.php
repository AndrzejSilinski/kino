<?php

namespace App\Exceptions;

/**
 * Nieudane logowanie.
 *
 * Komunikat jest CELOWO nierozróżniający: nie mówimy, czy zawiódł
 * e-mail, czy hasło. Rozróżnienie pozwoliłoby zbudować listę kont
 * istniejących w systemie (user enumeration), co jest pierwszym
 * krokiem do ataku słownikowego na konkretne konta.
 */
class InvalidCredentialsException extends CinemaException
{
    public function __construct()
    {
        parent::__construct('Nieprawidłowy adres e-mail lub hasło.');
    }

    public function status(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }
}
