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
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();

            // Publiczny numer zamówienia (ULID). Podawany w mailu i supportowi.
            // Sortowalny chronologicznie, nie zdradza liczby rezerwacji w systemie.
            $table->ulid('reference')->unique();

            // Danych sprzedazowych sie nie kasuje - rezerwacje sie anuluje.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('screening_id')->constrained()->restrictOnDelete();

            $table->string('status', 20)->default('pending');

            // Suma w groszach, wyliczona PO STRONIE SERWERA z cennika seansu.
            // Nigdy nie ufamy kwocie przyslanej z frontendu.
            $table->integer('total_amount');

            $table->char('currency', 3)->default('PLN');

            // Identyfikator Payment Intent u Stripe. Unikalny, zeby webhook
            // nie mogl dwa razy przypisac tej samej platnosci.
            $table->string('stripe_payment_intent_id', 255)->nullable()->unique();

            // Koniec okna platnosci - wynika z najkrotszej blokady miejsca.
            $table->timestampTz('expires_at')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            // Admin anulujacy rezerwacje musi podac powod (wymog zadania).
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Historia zakupow uzytkownika, najnowsze pierwsze.
            $table->index(['user_id', 'created_at']);

            // Panel admina: rezerwacje danego seansu wg statusu.
            $table->index(['screening_id', 'status']);

            // Scheduler wygaszajacy porzucone rezerwacje.
            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_total_non_negative CHECK (total_amount >= 0)');

        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_status_check
            CHECK (status IN ('pending', 'paid', 'cancelled', 'expired', 'refunded'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_total_non_negative');

        Schema::dropIfExists('bookings');
    }
};
