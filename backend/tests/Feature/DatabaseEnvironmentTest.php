<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bezpiecznik środowiska testowego.
 *
 * PO CO TAKI TEST:
 * cechy RefreshDatabase i DatabaseTruncation czyszczą bazę, na którą wskazuje
 * konfiguracja. Gdyby ktoś uruchomił testy z DB_DATABASE=cinema (albo, co gorsza,
 * z bazą produkcyjną), skasowałby dane bez ostrzeżenia. Ten test pada PIERWSZY
 * i mówi wprost, co jest źle skonfigurowane.
 *
 * Sprawdza też sterownik: gdyby ktoś przywrócił domyślne SQLite, migracje
 * z indeksem częściowym i constraintem EXCLUDE i tak by nie przeszły,
 * ale komunikat byłby dużo mniej czytelny niż ten.
 */
class DatabaseEnvironmentTest extends TestCase
{
    public function test_testy_uzywaja_postgresa(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Testy muszą działać na PostgreSQL — indeksy częściowe i EXCLUDE nie istnieją w SQLite.'
        );
    }

    public function test_testy_uzywaja_osobnej_bazy(): void
    {
        $database = DB::connection()->getDatabaseName();

        $this->assertStringEndsWith(
            '_testing',
            $database,
            "Testy czyszczą bazę. Wskazana baza to '{$database}' — użyj cinema_testing."
        );
    }
}
