<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Urządzenia do powiadomień push (Etap 8, blok K) — wspólne dla weba i Fluttera (Etap 9).
 *
 * token UNIKALNY globalnie: token FCM identyfikuje instalację aplikacji, nie konto. Gdy na tym
 * samym urządzeniu zaloguje się ktoś inny, rejestracja przenosi wiersz na nowe konto zamiast
 * wysyłać powiadomienia dwóm osobom.
 *
 * personal_access_token_id z ON DELETE CASCADE: urządzenie żyje tak długo, jak sesja, w której
 * je zarejestrowano. Wylogowanie, wylogowanie innych urządzeń po zmianie hasła i sprzątanie
 * wygasłych tokenów (sanctum:prune-expired) usuwają je w bazie, nawet gdy aplikacja nie zdąży
 * wyrejestrować urządzenia sama (zamknięta karta, brak sieci).
 *
 * bookings.payment_push_sent_at — push "płatność przyjęta" najwyżej raz (jak reminder_sent_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            // Tokeny FCM mają dziś ok. 160–200 znaków; 1024 to zapas mieszczący się w indeksie B-drzewa PostgreSQL.
            $table->string('token', 1024)->unique();
            $table->string('platform', 16);
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
        });

        DB::statement("ALTER TABLE push_devices ADD CONSTRAINT push_devices_platform_check CHECK (platform IN ('web', 'android', 'ios'))");

        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestampTz('payment_push_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('payment_push_sent_at');
        });

        Schema::dropIfExists('push_devices');
    }
};
