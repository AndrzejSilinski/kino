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
        Schema::create('seats', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('hall_id')
                ->constrained()
                ->cascadeOnDelete();

            // Kategorii cenowej nie wolno usunąć, dopóki wskazują na nią miejsca.
            $table->foreignId('price_category_id')
                ->constrained()
                ->restrictOnDelete();

            // "row" to słowo zastrzeżone w SQL, stąd przyrostek.
            // Trzymane jako string, żeby sala mogła używać "A".."Z" albo "1".."20".
            $table->string('row_label', 4);
            $table->smallInteger('seat_number');

            // Wyłącznie semantyka i sposób rysowania - cena wynika z price_category_id.
            $table->string('type', 20)->default('standard');

            // Współrzędne na siatce planu. Niezależne od rzędu i numeru, dzięki
            // czemu sala może mieć przejścia, przerwy i wygięte rzędy.
            $table->smallInteger('position_x');
            $table->smallInteger('position_y');

            // Miejsce można wyłączyć z użytku (uszkodzone) bez usuwania go,
            // co zepsułoby historyczne bilety wskazujące na ten fotel.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Tożsamość "ludzka": "Sala 1, rząd B, miejsce 7" jest unikalne.
            $table->unique(['hall_id', 'row_label', 'seat_number']);

            // Tożsamość geometryczna: dwa miejsca nie mogą zajmować tej samej
            // kratki planu. Zabezpiecza edytor układu sali przed samym sobą.
            $table->unique(['hall_id', 'position_x', 'position_y']);
        });

        // Schema Builder nie ma API dla constraintów CHECK, więc schodzimy do
        // surowego SQL. Trzymanie dozwolonych wartości w bazie sprawia, że ani
        // bug w kodzie, ani ręczny UPDATE nie wprowadzą typu miejsca, którego
        // frontend nie umie narysować.
        DB::statement("
            ALTER TABLE seats
            ADD CONSTRAINT seats_type_check
            CHECK (type IN ('standard', 'double', 'accessible'))
        ");
    }

    public function down(): void
    {
        // Usunięcie tabeli i tak kasuje jej constrainty, ale jawny zapis czyni
        // down() czytelnym i bezpiecznym przy zmianie kolejności migracji.
        DB::statement('ALTER TABLE seats DROP CONSTRAINT IF EXISTS seats_type_check');

        Schema::dropIfExists('seats');
    }
};
