<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Kopiowania repertuaru nie wykonano, bo choć jeden seans nie przeszedł reguł (Etap 7, blok H).
 *
 * 409 i ZERO zmian w bazie: repertuar dnia jest całością (sale, godziny, przerwy),
 * a skopiowanie połowy zostawiłoby dzień, którego nikt nie zaplanował.
 * Kontekst zawiera cały raport — ten sam, który pokazuje podgląd.
 */
final class RepertoireCopyBlockedException extends CinemaException
{
    /**
     * @param  list<array{hall: string, time: string, movie: string, status: string, message: ?string}>  $items
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        public readonly array $items = [],
    ) {
        parent::__construct($message);
    }

    /** @param list<array{hall: string, time: string, movie: string, status: string, message: ?string}> $items */
    public static function problems(array $items): self
    {
        $count = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'problem'));

        return new self("Nie skopiowano niczego: {$count} seans(ów) nie da się zaplanować w dniu docelowym. Popraw je albo usuń kolizje i spróbuj ponownie.", 'problems', $items);
    }

    public static function targetTooEarly(): self
    {
        return new self('Repertuar można kopiować najwcześniej na jutro (dzień w strefie kina).', 'target_too_early');
    }

    public static function sameDay(): self
    {
        return new self('Dzień źródłowy i docelowy muszą być różne.', 'same_day');
    }

    public static function emptySource(): self
    {
        return new self('W dniu źródłowym nie ma seansów do skopiowania (odwołane pomijamy).', 'empty_source');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'REPERTOIRE_COPY_BLOCKED';
    }

    public function context(): array
    {
        return ['reason' => $this->reason, 'items' => $this->items];
    }
}
