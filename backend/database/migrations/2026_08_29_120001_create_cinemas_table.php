<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinemas', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('city', 80);
            $table->string('address', 255);

            // Identyfikator IANA, np. "Europe/Warsaw". Wszystkie znaczniki czasu
            // w bazie są w UTC; ta strefa służy wyłącznie do wyświetlania godzin
            // seansów tego konkretnego kina.
            $table->string('timezone', 64)->default('Europe/Warsaw');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Publiczna lista kin jest zawsze filtrowana po statusie aktywności
            // i grupowana po mieście - ten indeks złożony obsługuje dokładnie to
            // zapytanie.
            $table->index(['is_active', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinemas');
    }
};
