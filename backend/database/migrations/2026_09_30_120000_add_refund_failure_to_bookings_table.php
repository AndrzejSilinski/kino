<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nieudany zwrot (Etap 10, blok C, zdarzenie refund.failed).
 *
 * refund_failed_at      — operator odrzucił zwrot, który wcześniej przyjął; pieniądze wróciły
 *                         na konto kina, a nie do klienta.
 * refund_failure_reason — kod dostawcy (np. expired_or_canceled_card), opis po polsku w panelu.
 *
 * Osobny znacznik, a nie nowy status rezerwacji: status mówi, co się stało z miejscami i biletami
 * ("anulowana"), a los pieniędzy opisują znaczniki rozliczenia — tak jak refund_requested_at
 * i refund_completed_at z Etapu 7. Nowy status rozszedłby się po API, froncie i aplikacji mobilnej.
 *
 * CHECK: nie da się zapisać nieudanego zwrotu, którego nikt nie zlecił.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestampTz('refund_failed_at')->nullable();
            $table->string('refund_failure_reason', 50)->nullable();
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_refund_failed_after_request CHECK (refund_failed_at IS NULL OR refund_requested_at IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_refund_failed_after_request');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['refund_failed_at', 'refund_failure_reason']);
        });
    }
};
