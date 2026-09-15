<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_locks', function (Blueprint $table): void {
            $table->id();

            // Blokady sa efemeryczne - usuniecie seansu lub miejsca kasuje je
            // bez zalu (w przeciwienstwie do biletow).
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();

            // Wlascicielem blokady jest SESJA koszyka - dziala tez dla goscia
            // przegladajacego plan sali przed zalogowaniem.
            $table->string('session_id', 64);

            // Wypelnione, gdy blokade zalozyl zalogowany uzytkownik.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Wypelniane w chwili, gdy blokada zostaje skonsumowana przez
            // rezerwacje - kluczowe przy obsludze wyscigu podczas platnosci.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // Moment wygasniecia = utworzenie + config('cinema.seat_lock.ttl').
            $table->timestampTz('expires_at');

            // Zwolnienie zamiast usuniecia wiersza - zostaje slad audytowy,
            // bezcenny przy debugowaniu wyscigow.
            $table->timestampTz('released_at')->nullable();

            $table->timestamps();

            // "Moje blokady na tym seansie" - odtworzenie koszyka po reconnect.
            $table->index(['screening_id', 'session_id']);
        });

        // SERCE ETAPU 2.
        // Przy N rownoczesnych requestach na to samo miejsce PostgreSQL
        // przepusci dokladnie jeden INSERT; pozostale dostana unique violation,
        // ktora serwis zamieni na 409 Conflict. Zaden lock aplikacyjny nie jest
        // potrzebny - rozstrzyga sama baza.
        //
        // UWAGA: w predykacie NIE MOZE byc "AND expires_at > now()", bo
        // PostgreSQL wymaga tam funkcji IMMUTABLE, a now() taka nie jest.
        // Dlatego wygasla, ale niezwolniona blokada nadal zajmuje miejsce
        // w indeksie - serwis musi ja zwolnic w tej samej transakcji, zanim
        // sprobuje wstawic wlasna.
        DB::statement("
            CREATE UNIQUE INDEX seat_locks_active_unique
            ON seat_locks (screening_id, seat_id)
            WHERE released_at IS NULL
        ");

        // Indeks pod zadanie schedulera czyszczace wygasle blokady.
        // Rowniez czesciowy - interesuja nas wylacznie aktywne wiersze.
        DB::statement('
            CREATE INDEX seat_locks_expiry_sweep
            ON seat_locks (expires_at)
            WHERE released_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS seat_locks_expiry_sweep');
        DB::statement('DROP INDEX IF EXISTS seat_locks_active_unique');

        Schema::dropIfExists('seat_locks');
    }
};
