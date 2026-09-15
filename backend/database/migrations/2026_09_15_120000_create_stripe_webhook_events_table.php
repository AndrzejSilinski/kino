<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dziennik przetworzonych zdarzeń webhooka Stripe'a.
 *
 * PO CO: Stripe dostarcza to samo zdarzenie wielokrotnie (ponowienia po
 * błędzie, timeouty). Ta tabela pozwala rozpoznać powtórkę i daje ślad
 * audytowy: co przyszło, kiedy i co z tym zrobiliśmy.
 *
 * CZEGO TU NIE MA: treści zdarzenia. Payload Stripe'a zawiera dane
 * osobowe płacącego (billing_details), a wymóg jakościowy zadania
 * zabrania trzymania takich danych w logach. Do audytu wystarczą
 * identyfikatory; pełne zdarzenie widać w Dashboardzie Stripe'a.
 *
 * WIERSZ POWSTAJE PO PRZETWORZENIU, nie przed. Zapis na wejściu
 * sprawiłby, że przerwane przetwarzanie (np. nieudany capture) zostałoby
 * przy ponowieniu uznane za zrobione i nigdy by się nie dokończyło.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            // Kluczem głównym jest identyfikator zdarzenia ze Stripe'a
            // (evt_...). Własne id byłoby tu zbędne: unikalność gwarantuje
            // Stripe, a my chcemy, żeby drugi zapis tego samego zdarzenia
            // uderzył wprost w klucz główny.
            $table->string('event_id', 64)->primary();

            // Typ zdarzenia, np. payment_intent.amount_capturable_updated.
            $table->string('type', 64);

            // Powiązanie z płatnością i rezerwacją — do analizy po fakcie.
            $table->string('payment_intent_id', 64)->nullable()->index();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // Czas powstania zdarzenia PO STRONIE STRIPE'A. Różnica między
            // nim a processed_at to opóźnienie dostarczenia — przy analizie
            // wyścigu z wygasającą blokadą patrzy się na to najpierw.
            $table->timestampTz('stripe_created_at')->nullable();

            // Krótki, maszynowy wynik przetworzenia: tickets_issued,
            // captured, seats_lost, refunded, ignored. Audyt bez payloadu.
            $table->string('outcome', 32)->nullable();

            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
