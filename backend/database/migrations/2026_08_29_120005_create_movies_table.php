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
        Schema::create('movies', function (Blueprint $table): void {
            $table->id();

            $table->string('title', 200);
            $table->string('slug', 220)->unique();

            // Tytuł oryginalny - kina pokazują go obok polskiego.
            $table->string('original_title', 200)->nullable();

            $table->text('description');

            // Czas trwania samego filmu, bez reklam. Podstawa wyliczenia
            // ends_at seansu, dlatego musi być dodatni (CHECK poniżej).
            $table->smallInteger('duration_minutes');

            // Ścieżka do pliku w storage, nie URL.
            $table->string('poster_path', 255)->nullable();

            // Polska kategoria wiekowa: "B/O", "7", "12", "15", "16", "18".
            $table->string('age_rating', 10);

            // Lista gatunków, np. ["Sci-Fi","Thriller"].
            $table->jsonb('genres');

            $table->date('premiere_date')->nullable();

            // Film wycofany znika z listingów, ale zostaje w bazie - historyczne
            // seanse i bilety muszą mieć na co wskazywać.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });

        DB::statement('ALTER TABLE movies ADD CONSTRAINT movies_duration_positive CHECK (duration_minutes > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE movies DROP CONSTRAINT IF EXISTS movies_duration_positive');

        Schema::dropIfExists('movies');
    }
};
