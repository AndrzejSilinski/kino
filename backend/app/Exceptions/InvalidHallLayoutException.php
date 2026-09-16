<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Układ sali, który nie da się zapisać (Etap 7, blok E): nakładające się miejsca,
 * miejsce podwójne wychodzące na sąsiada, nieznana kategoria, pusty układ.
 *
 * 422, bo to błąd TREŚCI żądania (jak walidacja), a nie konflikt ze stanem
 * sprzedaży — ten zgłasza StructureChangeBlockedException (409).
 * Wszystkie problemy naraz w context()['problems'], żeby edytor pokazał listę,
 * a nie kazał poprawiać błędy po jednym.
 */
final class InvalidHallLayoutException extends CinemaException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Układ sali zawiera błędy: '.count($problems).'.');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'HALL_LAYOUT_INVALID';
    }

    public function context(): array
    {
        return ['problems' => $this->problems];
    }
}
