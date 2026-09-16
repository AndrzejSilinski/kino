<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Seans nachodzi na inny seans w tej samej sali (Etap 7, blok G).
 *
 * 409 Conflict. Dwie drogi, ten sam wyjątek:
 * 1. wstępne sprawdzenie w serwisie — z listą kolidujących seansów;
 * 2. naruszenie constraintu screenings_no_overlap (SQLSTATE 23P01), gdy wiersz
 *    wstawił ktoś, kto ominął serwis (np. równoległy seeder) — wtedy bez listy,
 *    bo transakcja jest już przerwana.
 *
 * Kontekst zawiera tylko dane repertuaru (id seansu, tytuł, godziny).
 */
final class ScreeningConflictException extends CinemaException
{
    /** @param list<array{id: int, movie: string, starts_at: string, slot_ends_at: string}> $conflicts */
    public function __construct(
        public readonly array $conflicts,
        public readonly bool $detectedByDatabase = false,
    ) {
        $list = implode('; ', array_map(
            static fn (array $c): string => "{$c['movie']} {$c['starts_at']}–{$c['slot_ends_at']}",
            array_slice($conflicts, 0, 3),
        ));

        parent::__construct($conflicts === []
            ? 'Seans nachodzi na inny seans w tej sali (z reklamami i sprzątaniem).'
            : 'Seans nachodzi na inny seans w tej sali (z reklamami i sprzątaniem): '.$list.'.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'SCREENING_CONFLICT';
    }

    public function context(): array
    {
        return ['conflicts' => $this->conflicts];
    }
}
