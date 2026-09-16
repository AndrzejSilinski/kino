<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rozliczenie płatności po anulowaniu rezerwacji przez administratora (Etap 7, blok K).
 *
 * refund_requested_at — anulowanie zapisane w bazie, płatność u operatora czeka na
 *   rozliczenie (zwolnienie autoryzacji albo zwrot pobranych pieniędzy).
 * refund_completed_at — operator potwierdził rozliczenie.
 *
 * Dwa znaczniki zamiast jednego statusu: anulowanie w bazie i rozmowa z operatorem
 * to dwa kroki, między którymi proces może paść albo sieć zawieść. Para
 * "zlecono, nie zakończono" to dokładnie lista zaległości dla komendy ponawiającej.
 *
 * INDEKS CZĘŚCIOWY bookings_refund_pending obejmuje tylko zaległe rozliczenia —
 * w normalnej pracy zero albo kilka wierszy, niezależnie od historii sprzedaży.
 * CHECK pilnuje, że nie da się zakończyć rozliczenia, którego nikt nie zlecił.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestampTz('refund_requested_at')->nullable();
            $table->timestampTz('refund_completed_at')->nullable();
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_refund_completed_after_request CHECK (refund_completed_at IS NULL OR refund_requested_at IS NOT NULL)');

        DB::statement('CREATE INDEX bookings_refund_pending ON bookings (refund_requested_at) WHERE refund_requested_at IS NOT NULL AND refund_completed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_refund_pending');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_refund_completed_after_request');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['refund_requested_at', 'refund_completed_at']);
        });
    }
};
