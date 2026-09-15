<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('halls', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cinema_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 60);

            // Technologie projekcji, które ta sala fizycznie obsługuje,
            // np. ["2d","3d"]. Seans może użyć wyłącznie typu z tej listy;
            // regułę egzekwuje warstwa aplikacji (FormRequest), bo wymaga
            // porównania dwóch wierszy.
            $table->jsonb('projection_types');

            // Wymiary siatki planu sali - frontend używa ich do wyznaczenia
            // rozmiaru widoku top-view bez wczytywania wszystkich miejsc.
            // Celowa denormalizacja; przeliczana przez edytor układu sali.
            $table->smallInteger('grid_rows');
            $table->smallInteger('grid_cols');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Dwie sale w jednym kinie nie mogą mieć tej samej nazwy ("Sala 1").
            $table->unique(['cinema_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('halls');
    }
};
