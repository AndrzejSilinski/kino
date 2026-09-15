<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_categories', function (Blueprint $table): void {
            $table->id();

            // Stały klucz techniczny używany w kodzie i seederach (np. "vip").
            // Nigdy się nie zmienia.
            $table->string('slug', 40)->unique();

            // Nazwa pokazywana klientowi (np. "Fotel VIP"). Admin może ją
            // dowolnie edytować bez wpływu na kod.
            $table->string('name', 60);

            // Kolor w formacie hex, którym ta kategoria jest malowana na planie sali.
            $table->string('color', 7);

            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_categories');
    }
};
