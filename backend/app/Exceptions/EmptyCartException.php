<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Próba przejścia do płatności bez ani jednego zablokowanego miejsca.
 *
 * 422, bo żądanie jest poprawne składniowo, ale stan koszyka go nie
 * uzasadnia. Najczęstsza przyczyna w praktyce: blokady wygasły, gdy
 * klient zostawił otwartą kartę i wrócił po kwadransie.
 */
class EmptyCartException extends CinemaException
{
    public function __construct()
    {
        parent::__construct('Koszyk jest pusty albo blokady miejsc wygasły. Wybierz miejsca ponownie.');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'EMPTY_CART';
    }
}
