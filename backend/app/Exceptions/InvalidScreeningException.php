<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Seans, którego nie da się zaplanować w tej postaci (Etap 7, blok G).
 *
 * 422: problem leży w danych formularza (godzina, sala, film, cennik), a nie
 * w kolizji z innym seansem — ta ma osobny wyjątek i status 409.
 */
final class InvalidScreeningException extends CinemaException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly string $field,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function badTime(string $input): self
    {
        return new self("Nieprawidłowa data lub godzina: {$input}. Użyj formatu RRRR-MM-DD i GG:MM.", 'bad_time', 'startsAt');
    }

    public static function nonexistentLocalTime(string $input, string $timezone): self
    {
        return new self("Godzina {$input} nie istnieje w strefie {$timezone} (zmiana czasu na letni). Wybierz inną godzinę.", 'time_nonexistent', 'startsAt');
    }

    public static function ambiguousLocalTime(string $input, string $timezone): self
    {
        return new self("Godzina {$input} występuje dwa razy w strefie {$timezone} (zmiana czasu na zimowy). Wybierz inną godzinę.", 'time_ambiguous', 'startsAt');
    }

    public static function inPast(): self
    {
        return new self('Seans musi zaczynać się w przyszłości.', 'in_past', 'startsAt');
    }

    public static function hallInactive(): self
    {
        return new self('Sala albo kino jest wyłączone — nie można w nim planować seansów.', 'hall_inactive', 'hallId');
    }

    public static function hallFromOtherCinema(): self
    {
        return new self('Seans można przenieść tylko do sali tego samego kina.', 'hall_other_cinema', 'hallId');
    }

    public static function hallWithoutSeats(): self
    {
        return new self('Sala nie ma aktywnych miejsc — najpierw ustaw jej układ.', 'hall_without_seats', 'hallId');
    }

    public static function movieInactive(): self
    {
        return new self('Film jest wyłączony — nie można planować jego seansów.', 'movie_inactive', 'movieId');
    }

    public static function projectionUnsupported(string $type, string $hall): self
    {
        return new self("Sala {$hall} nie obsługuje projekcji {$type}.", 'projection_unsupported', 'projectionType', ['projection_type' => $type]);
    }

    /** @param list<string> $categories */
    public static function pricesMissing(array $categories): self
    {
        return new self('Uzupełnij ceny dla kategorii miejsc w tej sali: '.implode(', ', $categories).'.', 'prices_missing', 'prices', ['categories' => $categories]);
    }

    public static function priceOutOfRange(int $maxGrosze): self
    {
        return new self('Cena musi być liczbą od 0 do '.number_format($maxGrosze / 100, 2, ',', ' ').' zł.', 'price_out_of_range', 'prices');
    }

    public static function unknownPriceCategory(int $id): self
    {
        return new self("Nieznana kategoria cenowa (id {$id}).", 'price_category_unknown', 'prices');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'SCREENING_INVALID';
    }

    /** Pole formularza, przy którym panel pokazuje komunikat. */
    public function field(): string
    {
        return $this->field;
    }

    public function context(): array
    {
        return ['reason' => $this->reason, ...$this->details];
    }
}
