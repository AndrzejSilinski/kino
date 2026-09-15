<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingPaid;
use App\Jobs\GenerateBookingTicketsPdf;
use Throwable;

/**
 * Po opłaceniu rezerwacji wstawia do kolejki generowanie PDF-a (decyzja 73).
 *
 * Słuchacz jest SYNCHRONICZNY i robi jedną rzecz: dispatch. Ciężka praca
 * (PDF, mail) dzieje się w workerze, więc webhook Stripe'a odpowiada
 * w milisekundach.
 *
 * Wyjątek przy wstawianiu do kolejki (np. Redis niedostępny) jest łapany
 * i raportowany, ale NIE przerywa obsługi webhooka. Pieniądze są już
 * pobrane — odpowiedź 500 skłoniłaby Stripe'a do ponowień, które niczego
 * nie naprawią. Zgubione potwierdzenie podniesie komenda schedulera
 * (bookings.confirmation_sent_at IS NULL, Blok G).
 */
final class QueueBookingConfirmation
{
    public function handle(BookingPaid $event): void
    {
        try {
            $pending = GenerateBookingTicketsPdf::dispatch($event->bookingId);

            // PendingDispatch wstawia zadanie do kolejki dopiero w destruktorze.
            // unset() wymusza to TUTAJ, wewnątrz try — bez tego wyjątek z kolejki
            // poleciałby po wyjściu z bloku i nie zostałby złapany.
            unset($pending);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
