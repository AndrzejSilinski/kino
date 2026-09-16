<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Zmiana struktury kina, której nie wolno wykonać przy obecnym stanie sprzedaży
 * (Etap 7, blok D).
 *
 * 409 Conflict: żądanie jest poprawne, ale koliduje ze stanem zasobów — w kinie
 * albo sali są nadchodzące seanse, na które mogą już istnieć blokady i bilety.
 * Wyłączenie sali pod sprzedanymi biletami to problem biznesowy (klienci przyjdą
 * na seans), a nie techniczny, więc system go nie rozstrzyga po cichu.
 *
 * Jedna klasa z fabrykami, jak TicketValidationException (decyzja 81): każdy
 * przypadek ma własny kod błędu, a komunikat mówi, co zrobić dalej.
 */
final class StructureChangeBlockedException extends CinemaException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $details,
    ) {
        parent::__construct($message);
    }

    public static function cinemaDeactivation(int $upcoming): self
    {
        return new self(
            "Nie można wyłączyć kina, które ma nadchodzące seanse ({$upcoming}). Najpierw je odwołaj.",
            'CINEMA_HAS_UPCOMING_SCREENINGS',
            ['upcoming_screenings' => $upcoming],
        );
    }

    public static function cinemaTimezoneChange(int $upcoming): self
    {
        return new self(
            "Nie można zmienić strefy czasowej kina, które ma nadchodzące seanse ({$upcoming}): klienci zobaczyliby inne godziny niż na biletach.",
            'CINEMA_TIMEZONE_LOCKED',
            ['upcoming_screenings' => $upcoming],
        );
    }

    public static function hallDeactivation(int $upcoming): self
    {
        return new self(
            "Nie można wyłączyć sali, która ma nadchodzące seanse ({$upcoming}). Najpierw je odwołaj.",
            'HALL_HAS_UPCOMING_SCREENINGS',
            ['upcoming_screenings' => $upcoming],
        );
    }

    /** @param list<string> $types */
    public static function hallProjectionTypesInUse(array $types, int $upcoming): self
    {
        return new self(
            'Nie można usunąć typu projekcji ('.implode(', ', $types)."), bo ma go {$upcoming} nadchodzących seansów w tej sali.",
            'HALL_PROJECTION_TYPE_IN_USE',
            ['projection_types' => $types, 'upcoming_screenings' => $upcoming],
        );
    }

    public static function movieDeactivation(int $upcoming): self
    {
        return new self(
            "Nie można wyłączyć filmu, który ma nadchodzące seanse ({$upcoming}). Najpierw je odwołaj.",
            'MOVIE_HAS_UPCOMING_SCREENINGS',
            ['upcoming_screenings' => $upcoming],
        );
    }

    /** Czas trwania wyznacza koniec seansu (ends_at), zapisany przy jego tworzeniu. */
    public static function movieDurationChange(int $upcoming): self
    {
        return new self(
            "Nie można zmienić czasu trwania filmu, który ma nadchodzące seanse ({$upcoming}): ich godziny zakończenia i przerwy między seansami zostały policzone ze starej długości.",
            'MOVIE_DURATION_LOCKED',
            ['upcoming_screenings' => $upcoming],
        );
    }

    public static function hallNameTaken(string $name): self
    {
        return new self(
            "Sala o nazwie \"{$name}\" już istnieje w tym kinie.",
            'HALL_NAME_TAKEN',
            ['name' => $name],
        );
    }

    /** Sala z historią sprzedaży albo nadchodzącymi seansami: tożsamość miejsc jest zamrożona. */
    public static function hallLayoutRestricted(): self
    {
        return new self(
            'Ta sala ma historię sprzedaży albo nadchodzące seanse: można zmieniać tylko kategorię, typ (standard / dla niepełnosprawnych) i dostępność miejsc. Dodawanie, usuwanie i przesuwanie miejsc wymaga nowej sali.',
            'HALL_LAYOUT_RESTRICTED',
            [],
        );
    }

    /** @param list<string> $labels */
    public static function hallLayoutSeatsHeld(array $labels): self
    {
        return new self(
            'Trwa sprzedaż miejsc: '.implode(', ', $labels).' (blokada albo oczekująca płatność na nadchodzącym seansie). Spróbuj za kilka minut.',
            'HALL_LAYOUT_SEATS_HELD',
            ['seats' => $labels],
        );
    }

    /** @param list<string> $labels */
    public static function hallLayoutSeatsSold(array $labels): self
    {
        return new self(
            'Nie można wyłączyć miejsc sprzedanych na nadchodzące seanse: '.implode(', ', $labels).'.',
            'HALL_LAYOUT_SEATS_SOLD',
            ['seats' => $labels],
        );
    }

    /** @param list<string> $categories */
    public static function hallLayoutPricesMissing(array $categories, int $screenings): self
    {
        return new self(
            'Brak cen dla kategorii: '.implode(', ', $categories)." w cenniku {$screenings} nadchodzących seansów tej sali. Najpierw uzupełnij cenniki.",
            'HALL_LAYOUT_PRICES_MISSING',
            ['categories' => $categories, 'screenings' => $screenings],
        );
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->details;
    }
}
