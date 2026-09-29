<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Exceptions\BookingCancellationException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\User;
use App\Notifications\BookingCancelledByCinema;
use App\Services\BookingService;
use App\Support\ScreeningCancellationReport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odwołanie seansu RAZEM z jego rezerwacjami (Etap 9, blok L).
 *
 * Do tej pory seans z rezerwacjami można było odwołać tylko po ręcznym anulowaniu każdej z nich
 * osobno — przy pełnej sali to setki kliknięć, a każde z osobnym powodem wpisywanym ręcznie.
 * Ta usługa robi to jednym przebiegiem. Trzy rozstrzygnięcia, bez których nie działałaby dobrze:
 *
 * 1. NIE ROZMAWIAMY ZE STRIPE'EM W PĘTLI (decyzja 344). Zwrot to żądanie sieciowe; przy
 *    czterdziestu rezerwacjach pętla trwałaby minuty w jednym żądaniu HTTP z panelu, a awaria
 *    przy trzydziestej dziewiątej zostawiłaby trzydzieści osiem zwrotów już wysłanych i żadnego
 *    sposobu, żeby je cofnąć. Zamiast tego robimy krok bazodanowy (miejsca wracają do sprzedaży
 *    natychmiast, klient dostaje powiadomienie natychmiast), a rozliczenie zostaje na liście
 *    zaległych, którą i tak co pięć minut przerabia `cinema:bookings:retry-refunds`. To nie jest
 *    obejście — to ta sama ścieżka, którą system przewidział na niedostępność operatora.
 *
 * 2. KAŻDA REZERWACJA W OSOBNEJ TRANSAKCJI (decyzja 345). Jedna transakcja na całą pętlę
 *    trzymałaby blokady wierszy przez cały przebieg i cofnęłaby wszystko przy jednym błędzie.
 *    Osobne transakcje znaczą, że kłopot dotyczy jednego klienta, a raport mówi, którego.
 *
 * 3. AWARIA JEDNEJ REZERWACJI NIE PRZERYWA RESZTY, ALE BLOKUJE ODWOŁANIE SEANSU (decyzja 346).
 *    Gdybyśmy odwołali seans mimo nieanulowanej rezerwacji, ktoś zostałby z ważnym biletem na
 *    seans, którego nie ma — i dowiedziałby się o tym pod drzwiami sali.
 */
final class ScreeningCancellationService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly ScreeningAdminService $screenings,
    ) {}

    /**
     * @throws BookingCancellationException gdy powód nie spełnia wymagań
     */
    public function cancelWithBookings(
        Screening $screening,
        User $admin,
        string $reason,
    ): ScreeningCancellationReport {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        // Powód sprawdzamy RAZ, przed pierwszym anulowaniem. Inaczej zły powód
        // przerwałby przebieg dopiero po zmianie stanu pierwszej rezerwacji.
        if ($length < BookingCancellationException::REASON_MIN || $length > BookingCancellationException::REASON_MAX) {
            throw BookingCancellationException::invalidReason();
        }

        /** @var list<int> $ids */
        $ids = Booking::query()
            ->where('screening_id', $screening->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Paid])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $cancelled = 0;
        $refundsPending = 0;
        $failed = [];

        foreach ($ids as $id) {
            $booking = Booking::query()->find($id);

            if ($booking === null) {
                continue;
            }

            try {
                $result = $this->bookings->cancelByAdmin($booking, $admin, $reason);
            } catch (Throwable $e) {
                // Log bez danych osobowych: numer rezerwacji i klasa błędu.
                Log::warning('Nie udało się anulować rezerwacji przy odwoływaniu seansu.', [
                    'booking' => $booking->reference,
                    'screening' => $screening->id,
                    'error' => $e::class,
                ]);
                $failed[] = $booking->reference;

                continue;
            }

            $cancelled++;

            if ($result->refund_requested_at !== null) {
                $refundsPending++;
            }

            $this->notify($result);
        }

        $screeningCancelled = false;

        if ($failed === []) {
            $this->screenings->cancel($screening);
            $screeningCancelled = true;
        }

        return new ScreeningCancellationReport(
            cancelled: $cancelled,
            refundsPending: $refundsPending,
            failed: $failed,
            screeningCancelled: $screeningCancelled,
        );
    }

    /**
     * Powiadomienie po anulowaniu — mail zawsze, push przy zgodzie (blok K).
     *
     * Awaria kolejki nie może zamienić udanego anulowania w błąd panelu: pieniądze i miejsca
     * są już rozstrzygnięte, a nieudane powiadomienie to rzecz do naprawienia osobno.
     * Ta sama kolejność co w `PaymentService::cancelByAdmin`.
     */
    private function notify(Booking $booking): void
    {
        try {
            $booking->user()->firstOrFail()->notify(new BookingCancelledByCinema((int) $booking->id));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
