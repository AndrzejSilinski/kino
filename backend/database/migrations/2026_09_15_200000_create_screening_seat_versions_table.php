<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wersja stanu miejsc per seans (Etap 6, decyzja 2.2a).
 *
 * Jeden wiersz na seans, tworzony leniwie przy pierwszej zmianie stanu
 * (INSERT ... ON CONFLICT DO UPDATE w SeatStateRecorder). Seans, którego
 * miejsc nikt jeszcze nie ruszał, nie ma wiersza — jego wersja to 0.
 *
 * DLACZEGO OSOBNA TABELA, a nie kolumna w screenings: licznik jest
 * podbijany pod blokadą wiersza na końcu każdej transakcji zmieniającej
 * miejsca. Blokowanie wiersza screenings kolidowałoby z edycją repertuaru
 * w panelu (Etap 7), a częste UPDATE-y puchłyby tabelę, którą czyta cały
 * katalog. Mała, wąska tabela izoluje ten ruch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_seat_versions', function (Blueprint $table): void {
            // Klucz główny = seans. Blokada wiersza przy podbiciu dotyczy więc
            // wyłącznie jednego seansu; różne seanse nie czekają na siebie.
            $table->foreignId('screening_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('version');
            $table->timestampTz('updated_at');
        });

        // Wiersz powstaje od razu z wersją 1, więc 0 i wartości ujemne
        // oznaczałyby błąd w kodzie, a nie stan do obsłużenia.
        DB::statement('ALTER TABLE screening_seat_versions ADD CONSTRAINT screening_seat_versions_positive CHECK (version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_seat_versions');
    }
};
