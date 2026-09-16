<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indeksy pulpitu panelu (Etap 7, blok L).
 *
 * bookings_paid_at             — sprzedaż dziś i top filmów z 7 dni: zakres po paid_at.
 *                                Częściowy: pending, wygasłe i porzucone (paid_at NULL)
 *                                to większość wierszy i nigdy nie trafiają do tych zapytań.
 * bookings_refund_completed_at — zwroty dziś; w praktyce garstka wierszy.
 * bookings_updated_at          — feed: ostatnio zmienione rezerwacje, ORDER BY … LIMIT.
 *
 * Bez nich każde odświeżenie pulpitu (co minutę, na każdej otwartej karcie) czytałoby
 * całą historię sprzedaży. EXPLAIN w teście dymnym bloku L.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX bookings_paid_at ON bookings (paid_at) WHERE paid_at IS NOT NULL');
        DB::statement('CREATE INDEX bookings_refund_completed_at ON bookings (refund_completed_at) WHERE refund_completed_at IS NOT NULL');
        DB::statement('CREATE INDEX bookings_updated_at ON bookings (updated_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_updated_at');
        DB::statement('DROP INDEX IF EXISTS bookings_refund_completed_at');
        DB::statement('DROP INDEX IF EXISTS bookings_paid_at');
    }
};
