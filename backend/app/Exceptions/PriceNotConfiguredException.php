<?php

namespace App\Exceptions;

/**
 * Seans nie ma ceny dla kategorii, do której należy blokowane miejsce.
 *
 * To błąd danych, nie użytkownika — admin dodał seans i zapomniał
 * o cenniku dla jednej kategorii. Zwracamy 409, a nie 500, bo żądanie
 * jest poprawne; to stan zasobu uniemożliwia jego obsłużenie.
 *
 * Kluczowe: NIE zgadujemy ceny i nie przyjmujemy zera. Wycena koszyka
 * jest podstawą kwoty Payment Intentu w Etapie 4, a sprzedanie biletu
 * za 0 zł jest gorsze niż czytelny błąd.
 */
class PriceNotConfiguredException extends CinemaException
{
    public function __construct(
        private readonly int $priceCategoryId,
    ) {
        parent::__construct(
            'Dla tego seansu nie ustalono ceny jednego z wybranych miejsc. '
            .'Prosimy o kontakt z kasą kina.'
        );
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'PRICE_NOT_CONFIGURED';
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['price_category_id' => $this->priceCategoryId];
    }
}
