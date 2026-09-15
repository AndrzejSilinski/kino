<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Bazowy wyjątek domenowy aplikacji.
 *
 * PO CO TO JEST:
 * Serwisy to warstwa domenowa — nie wiedzą nic o HTTP i nie mogą zwracać
 * response'ów. Nie mogą też pozwolić, żeby na zewnątrz wyciekł QueryException,
 * bo ten niesie w komunikacie fragment SQL-a (wyciek szczegółów implementacji,
 * a w logach potencjalnie danych wrażliwych).
 *
 * Dlatego serwis rzuca wyjątek opisujący PROBLEM BIZNESOWY, a jedno miejsce
 * w bootstrap/app.php (dodamy je w Etapie 3) tłumaczy go na spójny JSON.
 * Ten sam wyjątek obsłuży więc REST API, komendę konsolową i Livewire.
 *
 * status()    — kod HTTP właściwy dla tego problemu
 * errorCode() — stały, maszynowy identyfikator błędu; frontend reaguje na niego,
 *               a nie na komunikat po polsku (komunikat może się zmienić)
 * context()   — dodatkowe dane dla klienta, np. które miejsca są zajęte
 */
abstract class CinemaException extends RuntimeException
{
    /** Kod HTTP, którym ten problem powinien się objawić w API. */
    abstract public function status(): int;

    /** Stały identyfikator błędu dla klientów API (SCREAMING_SNAKE_CASE). */
    abstract public function errorCode(): string;

    /**
     * Dodatkowe, bezpieczne do pokazania dane o błędzie.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
