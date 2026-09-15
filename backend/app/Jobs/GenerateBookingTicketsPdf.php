<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\BookingConfirmed;
use App\Queue\UsesRetryPolicy;
use App\Tickets\TicketPdfStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Generuje i zapisuje PDF z biletami, a potem zleca mail potwierdzający
 * (decyzja 74).
 *
 * IDENTYFIKATOR, NIE MODEL: zadanie niesie bookingId i samo ładuje świeży
 * stan z kompletem relacji. Model zapisany w zadaniu byłby zdjęciem
 * z chwili płatności, a rezerwacja mogła się od tego czasu zmienić.
 *
 * IDEMPOTENTNE, bo może wykonać się więcej niż raz (ponowienie po awarii,
 * komenda schedulera z Bloku G):
 *   - rezerwacja nie jest już opłacona  -> nic nie robimy,
 *   - PDF już jest                      -> zapis atomowy go podmienia,
 *   - potwierdzenie już wyszło          -> nie zlecamy maila drugi raz.
 *
 * ShouldBeUnique: dwa jednoczesne dispatche dla tej samej rezerwacji
 * (webhook i komenda ponawiająca) nie wejdą do kolejki równolegle.
 * Blokada unikalności siedzi w cache (Redis) i znika po zakończeniu
 * zadania albo po $uniqueFor sekundach.
 */
final class GenerateBookingTicketsPdf implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use UsesRetryPolicy;

    /** Limit jednej próby. Mniejszy niż --timeout workera (60 s). */
    public int $timeout = 45;

    /** Jak długo trzyma blokadę unikalności, jeśli zadanie nigdy się nie zakończy. */
    public int $uniqueFor = 600;

    public function __construct(public int $bookingId) {}

    public function uniqueId(): string
    {
        return (string) $this->bookingId;
    }

    public function handle(TicketPdfStore $store): void
    {
        $booking = Booking::query()
            ->with(['user', 'screening.movie', 'screening.hall.cinema', 'tickets.seat'])
            ->find($this->bookingId);

        if ($booking === null || $booking->status !== BookingStatus::Paid) {
            // Rezerwacja wycofana między płatnością a wykonaniem zadania.
            // To nie jest awaria, więc nie rzucamy wyjątku (brak ponowień).
            Log::info('Pominięto PDF z biletami: rezerwacja nie jest opłacona.', ['booking_id' => $this->bookingId]);

            return;
        }

        $store->store($booking);

        if ($booking->confirmation_sent_at === null) {
            $booking->user->notify(new BookingConfirmed($booking->id));
        }
    }
}
