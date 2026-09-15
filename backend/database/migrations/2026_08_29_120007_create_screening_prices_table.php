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
        Schema::create('screening_prices', function (Blueprint $table): void {
            $table->id();

            // Cennik jest częścią seansu - usunięcie seansu usuwa cennik.
            $table->foreignId('screening_id')->constrained()->cascadeOnDelete();

            $table->foreignId('price_category_id')->constrained()->restrictOnDelete();

            // Cena w groszach (integer, nigdy float). Stripe też operuje na
            // jednostkach minorowych, więc nie ma konwersji na granicy integracji.
            $table->integer('price');

            $table->timestamps();

            // Jedna cena na kategorię w ramach seansu.
            $table->unique(['screening_id', 'price_category_id']);
        });

        DB::statement('ALTER TABLE screening_prices ADD CONSTRAINT screening_prices_price_non_negative CHECK (price >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE screening_prices DROP CONSTRAINT IF EXISTS screening_prices_price_non_negative');

        Schema::dropIfExists('screening_prices');
    }
};
