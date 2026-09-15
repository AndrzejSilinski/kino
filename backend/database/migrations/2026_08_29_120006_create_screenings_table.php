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
        // Rozszerzenie potrzebne, żeby indeks GiST obsłużył zwykłą równość
        // na bigint (hall_id WITH =) obok operatora nakładania przedziałów.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('screenings', function (Blueprint $table): void {
            $table->id();

            // Filmu ani sali nie wolno usunąć, dopóki mają zaplanowane seanse.
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->foreignId('hall_id')->constrained()->restrictOnDelete();

            // Godzina podana klientowi.
            $table->timestampTz('starts_at');

            // Koniec filmu = starts_at + reklamy + czas trwania.
            $table->timestampTz('ends_at');

            // Koniec zajętości sali = ends_at + bufor na sprzątanie.
            // Używany WYŁĄCZNIE przez constraint kolizji.
            $table->timestampTz('slot_ends_at');

            // Musi należeć do halls.projection_types (weryfikuje FormRequest).
            $table->string('projection_type', 10);

            // original / subtitles / dubbing
            $table->string('language_version', 20);

            $table->string('status', 20)->default('scheduled');

            $table->timestamps();

            // Repertuar dnia dla sali oraz siatka tygodnia.
            $table->index(['hall_id', 'starts_at']);

            // Nadchodzące seanse do sprzedaży i dla schedulera.
            $table->index(['status', 'starts_at']);
        });

        // Spójność znaczników czasu - bez tego dałoby się zapisać seans,
        // który kończy się przed rozpoczęciem.
        DB::statement('ALTER TABLE screenings ADD CONSTRAINT screenings_time_order CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE screenings ADD CONSTRAINT screenings_slot_order CHECK (slot_ends_at >= ends_at)');

        DB::statement("
            ALTER TABLE screenings
            ADD CONSTRAINT screenings_projection_type_check
            CHECK (projection_type IN ('2d', '3d', 'imax'))
        ");

        DB::statement("
            ALTER TABLE screenings
            ADD CONSTRAINT screenings_language_version_check
            CHECK (language_version IN ('original', 'subtitles', 'dubbing'))
        ");

        DB::statement("
            ALTER TABLE screenings
            ADD CONSTRAINT screenings_status_check
            CHECK (status IN ('scheduled', 'cancelled', 'finished'))
        ");

        // SEDNO: dwa seanse nie moga zajmowac tej samej sali w nakladajacych sie
        // przedzialach czasu. Czytaj jako: nie moga istniec dwa wiersze, dla
        // ktorych jednoczesnie hall_id jest ROWNY oraz przedzialy
        // [starts_at, slot_ends_at) NACHODZA na siebie. Odwolane seanse
        // zwalniaja termin, ale zostaja w bazie dla historii.
        DB::statement("
            ALTER TABLE screenings
            ADD CONSTRAINT screenings_no_overlap
            EXCLUDE USING gist (
                hall_id WITH =,
                tstzrange(starts_at, slot_ends_at) WITH &&
            )
            WHERE (status <> 'cancelled')
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_no_overlap');
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_status_check');
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_language_version_check');
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_projection_type_check');
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_slot_order');
        DB::statement('ALTER TABLE screenings DROP CONSTRAINT IF EXISTS screenings_time_order');

        Schema::dropIfExists('screenings');

        // btree_gist celowo zostaje - moga z niego korzystac inne obiekty.
    }
};
