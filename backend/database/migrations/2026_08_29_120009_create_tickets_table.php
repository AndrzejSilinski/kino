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
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // ZDENORMALIZOWANE - wynika z booking.screening_id, ale bez tej
            // kolumny nie da sie zalozyc indeksu chroniacego przed podwojna
            // sprzedaza miejsca (indeks nie moze siegac do innej tabeli).
            $table->foreignId('screening_id')->constrained()->restrictOnDelete();

            $table->foreignId('seat_id')->constrained()->restrictOnDelete();

            // Ladunek kodu QR. UUID v4 = 122 losowe bity, nie do odgadniecia.
            // Swiadomie NIE ULID - ULID zdradza czas utworzenia w prefiksie.
            $table->uuid('code')->unique();

            // Cena tego konkretnego biletu w groszach, zamrozona w chwili zakupu.
            // Pozniejsza zmiana cennika seansu nie rusza wystawionych biletow.
            $table->integer('price');

            $table->string('status', 20)->default('valid');

            // Pola pod przyszla aplikacje obslugi skanujaca bilety przy wejsciu.
            $table->timestampTz('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Jedno miejsce moze wystapic w rezerwacji tylko raz.
            $table->unique(['booking_id', 'seat_id']);
        });

        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_price_non_negative CHECK (price >= 0)');

        DB::statement("
            ALTER TABLE tickets
            ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('valid', 'used', 'cancelled'))
        ");

        // OSTATECZNA GWARANCJA: jedno miejsce na jednym seansie nie moze byc
        // sprzedane dwa razy. Anulowane bilety zwalniaja miejsce do ponownej
        // sprzedazy, ale zostaja w bazie dla historii.
        // Indeks czesciowy - Schema Builder nie ma na to API.
        DB::statement("
            CREATE UNIQUE INDEX tickets_active_seat_unique
            ON tickets (screening_id, seat_id)
            WHERE status <> 'cancelled'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tickets_active_seat_unique');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_check');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_price_non_negative');

        Schema::dropIfExists('tickets');
    }
};
