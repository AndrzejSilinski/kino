<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znaczniki wysłania powiadomień o rezerwacji (decyzja 70).
 *
 * confirmation_sent_at — mail z biletami (Blok E),
 * reminder_sent_at     — przypomnienie przed seansem (Blok G).
 *
 * Znacznik czasu zamiast flagi boolean: ta sama idempotencja
 * ("wyślij tylko tam, gdzie IS NULL"), a do tego ślad, KIEDY poszło.
 *
 * INDEKS CZĘŚCIOWY bookings_confirmation_pending obejmuje wyłącznie
 * opłacone rezerwacje bez wysłanego potwierdzenia. W normalnej pracy to
 * garstka świeżych wierszy, więc indeks zostaje malutki, a komenda
 * ponawiająca potwierdzenia (Blok G) nie przegląda całej historii sprzedaży.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestampTz('confirmation_sent_at')->nullable();
            $table->timestampTz('reminder_sent_at')->nullable();
        });

        // Rezerwacje opłacone PRZED wprowadzeniem maili traktujemy jako
        // obsłużone. Bez tego pierwsze uruchomienie komendy ponawiającej
        // rozesłałoby potwierdzenia do wszystkich dawnych klientów.
        DB::statement("UPDATE bookings SET confirmation_sent_at = paid_at WHERE status = 'paid' AND paid_at IS NOT NULL");

        DB::statement("CREATE INDEX bookings_confirmation_pending ON bookings (paid_at) WHERE status = 'paid' AND confirmation_sent_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_confirmation_pending');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_sent_at', 'reminder_sent_at']);
        });
    }
};
