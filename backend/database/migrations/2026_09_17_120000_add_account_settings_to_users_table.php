<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konto klienta w SPA (Etap 8, blok I): avatar i ustawienia powiadomień.
 *
 * Kolumny w users, a nie osobna tabela ustawień: dwa przełączniki i ścieżka pliku to
 * dane 1:1 z kontem, czytane przy każdej wysyłce przypomnienia — JOIN do tabeli
 * ustawień nic by nie dał poza dodatkowym zapytaniem i wierszem do pilnowania.
 *
 * push_consent_at zamiast push_enabled (bool): moment wyrażenia zgody jest śladem
 * dla RODO ("kiedy klient się zgodził"), a NULL i tak czyta się jak "brak zgody".
 * screening_reminders domyślnie true: przypomnienia e-mail działały od Etapu 5
 * dla wszystkich — nowa kolumna nie może ich po cichu wyłączyć istniejącym kontom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // avatars/{40 znaków hex}.jpg — losowa nazwa, nową przy każdej zmianie.
            $table->string('avatar_path', 64)->nullable();
            $table->timestampTz('push_consent_at')->nullable();
            $table->boolean('screening_reminders')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'push_consent_at', 'screening_reminders']);
        });
    }
};
