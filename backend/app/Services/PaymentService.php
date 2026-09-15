<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Events\BookingPaid;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\PaymentRejectedException;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\WebhookEventData;
use Illuminate\Support\Facades\Log;

/**
 * Spina stan rezerwacji ze stanem płatności u dostawcy.
 *
 * BookingService dotyka tylko bazy, bramka tylko Stripe'a — dopiero tutaj
 * te dwa światy się spotykają. Dzięki temu kolejność operacji, czyli sedno
 * rozwiązania wyścigu, czyta się w jednym pliku.
 *
 * ŻADNE wywołanie dostawcy nie dzieje się wewnątrz transakcji bazy.
 * Transakcje są w BookingService i są krótkie; żądania HTTP trwają tyle,
 * ile cudza sieć, i nie mogą trzymać blokad na wierszach.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly BookingService $bookings,
    ) {}

    /**
     * Koszyk -> rezerwacja -> płatność gotowa do opłacenia przez klienta.
     *
     * @return array{0: Booking, 1: PaymentIntentData}
     */
    public function startCheckout(Screening $screening, string $sessionId, User $user): array
    {
        $booking = $this->bookings->checkout($screening, $sessionId, $user);

        return [$booking, $this->ensureIntent($booking)];
    }

    /**
     * Płatność dla rezerwacji — idempotentnie.
     *
     * Trzy niezależne zabezpieczenia przed dwiema płatnościami za te same
     * miejsca przy podwójnym kliknięciu "Zapłać":
     *   1. checkout() zwraca tę samą rezerwację (lockForUpdate na blokadach),
     *   2. zapisane stripe_payment_intent_id odsyła do istniejącej płatności,
     *   3. deterministyczny klucz idempotencji — nawet gdy oba żądania
     *      dobiegną do Stripe'a równocześnie, wróci ten sam intent.
     *
     * Kwota pochodzi WYŁĄCZNIE z rezerwacji, czyli z wyceny serwera.
     */
    public function ensureIntent(Booking $booking): PaymentIntentData
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw new BookingNotPayableException($booking->status);
        }

        if ($booking->stripe_payment_intent_id !== null) {
            return $this->gateway->retrieveIntent($booking->stripe_payment_intent_id);
        }

        $intent = $this->gateway->createIntent(
            (int) $booking->total_amount,
            (string) config('cinema.booking.currency'),
            $booking->reference,
            $this->key($booking, 'create-intent'),
        );

        // Zapis PO utworzeniu płatności. Gdyby proces padł pomiędzy,
        // ponowne wywołanie trafi w ten sam klucz idempotencji i dostanie
        // ten sam intent zamiast utworzyć drugi.
        $booking->stripe_payment_intent_id = $intent->id;
        $booking->save();

        return $intent;
    }

    /** Deterministyczny klucz idempotencji — nigdy losowy. */
    private function key(Booking $booking, string $operation): string
    {
        return 'booking:'.$booking->reference.':'.$operation;
    }

    /**
     * Obsługa zweryfikowanego zdarzenia. Zwraca krótki, maszynowy wynik
     * zapisywany w dzienniku zdarzeń.
     *
     * Wyjątek z tej metody kończy się odpowiedzią 500, a Stripe ponawia
     * dostarczenie — i o to chodzi: lepiej spróbować jeszcze raz, niż
     * odnotować zdarzenie jako obsłużone, gdy tak nie było.
     */
    public function handleEvent(WebhookEventData $event): string
    {
        $intent = $event->intent;

        if ($intent === null) {
            return 'ignored';
        }

        $booking = $this->findBooking($intent);

        if ($booking === null) {
            // Płatność spoza tego systemu albo rezerwacja skasowana.
            // Nie jest to nasz błąd, więc odpowiadamy 2xx i idziemy dalej.
            Log::warning('Zdarzenie Stripe bez rezerwacji.', ['intent' => $intent->id]);

            return 'unknown_booking';
        }

        return match ($event->type) {
            'payment_intent.amount_capturable_updated' => $this->onAuthorized($booking, $intent),
            'payment_intent.succeeded' => $this->onSucceeded($booking, $intent),
            'payment_intent.payment_failed' => $this->onFailed($booking, $intent),
            'payment_intent.canceled' => $this->onCanceled($booking),
            default => 'ignored',
        };
    }

    private function findBooking(PaymentIntentData $intent): ?Booking
    {
        $booking = Booking::query()
            ->where('stripe_payment_intent_id', $intent->id)
            ->first();

        if ($booking !== null || $intent->bookingReference === null) {
            return $booking;
        }

        // Zapas na wyścig: zdarzenie potrafi dotrzeć, zanim zdążymy zapisać
        // identyfikator płatności. Referencja jest w metadanych intentu.
        return Booking::query()->where('reference', $intent->bookingReference)->first();
    }

    /**
     * Karta autoryzowana: pieniądze są zablokowane, ale NIE pobrane.
     *
     * TU ROZSTRZYGA SIĘ WYŚCIG. Najpierw próbujemy zamienić blokady
     * w bilety. Dopiero gdy miejsca są nasze, pobieramy pieniądze.
     * Jeśli miejsc już nie ma — anulujemy autoryzację i klient nie
     * zobaczy żadnego obciążenia.
     */
    private function onAuthorized(Booking $booking, PaymentIntentData $intent): string
    {
        // Ponowienie po awarii: bilety są, pieniędzy jeszcze nie pobrano.
        if ($booking->status === BookingStatus::Paid) {
            $this->capture($booking);

            $this->announcePaid($booking);

            return 'captured';
        }

        if ($intent->amountMinor !== (int) $booking->total_amount) {
            $this->gateway->cancelIntent($intent->id, $this->key($booking, 'cancel'));

            return 'amount_mismatch';
        }

        try {
            $this->bookings->fulfil($booking);
        } catch (SeatsUnavailableException|BookingNotPayableException $e) {
            $this->bookings->expire($booking);
            $this->gateway->cancelIntent($intent->id, $this->key($booking, 'cancel'));
            Log::info('Autoryzacja anulowana: miejsca nie są już dostępne.', [
                'booking' => $booking->reference,
                'reason' => $e->errorCode(),
            ]);

            return 'seats_lost_canceled';
        }

        $this->capture($booking);

        $this->announcePaid($booking);

        return 'tickets_issued';
    }

    /**
     * Płatność pobrana. Dla karty to potwierdzenie naszego capture,
     * dla metod bez ręcznego capture (BLIK) pierwsza informacja o wpłacie.
     */
    private function onSucceeded(Booking $booking, PaymentIntentData $intent): string
    {
        if ($booking->status === BookingStatus::Paid) {
            return 'already_paid';
        }

        try {
            $this->bookings->fulfil($booking);

            $this->announcePaid($booking);

            return 'tickets_issued';
        } catch (SeatsUnavailableException|BookingNotPayableException) {
            // Pieniądze już są na koncie, a miejsc nie ma. Jedyne uczciwe
            // wyjście to zwrot — dlatego karty autoryzujemy bez pobrania
            // i ta gałąź dotyczy wyłącznie metod, które tego nie potrafią.
            $this->gateway->refundIntent($intent->id, $this->key($booking, 'refund'));
            $this->bookings->markRefunded($booking);

            return 'seats_lost_refunded';
        }
    }

    /**
     * Nieudana próba zapłaty. NIE zwalniamy miejsc: klient może poprawić
     * dane karty i spróbować ponownie w oknie płatności. Miejsca zwolni
     * dopiero wygaśnięcie rezerwacji albo anulowanie płatności.
     */
    private function onFailed(Booking $booking, PaymentIntentData $intent): string
    {
        Log::info('Nieudana próba płatności.', [
            'booking' => $booking->reference,
            'code' => $intent->lastErrorCode,
        ]);

        return 'payment_failed';
    }

    /** Płatność anulowana: przez nas przy wygaśnięciu albo z Dashboardu. */
    private function onCanceled(Booking $booking): string
    {
        if ($booking->status === BookingStatus::Paid) {
            // Autoryzacja przepadła po wystawieniu biletów — wycofujemy je,
            // bo nie mamy za nie pieniędzy.
            $this->bookings->revoke($booking);

            return 'tickets_revoked';
        }

        return $this->bookings->cancel($booking) ? 'canceled' : 'ignored';
    }

    /**
     * Rezerwacja opłacona i pieniądze pobrane: dopiero TERAZ ogłaszamy to
     * reszcie systemu (PDF i mail, w Etapie 6 panel admina). Decyzja 72:
     * najpierw miejsce, potem pieniądze, na końcu powiadomienie.
     */
    private function announcePaid(Booking $booking): void
    {
        BookingPaid::dispatch($booking->id);
    }

    private function capture(Booking $booking): void
    {
        $this->gateway->captureIntent(
            (string) $booking->stripe_payment_intent_id,
            $this->key($booking, 'capture'),
        );
    }

    /**
     * Wygasza nieopłacone rezerwacje po terminie i anuluje ich płatności.
     * Wejście dla schedulera.
     *
     * Anulowanie płatności jest tu równie ważne jak zwolnienie miejsc:
     * bez niego klient, który akurat kończy 3-D Secure, zapłaciłby za
     * miejsca oddane już komuś innemu. Po anulowaniu jego potwierdzenie
     * kończy się błędem, a żadne pieniądze nie zostają pobrane.
     *
     * Kolejność jest celowa: najpierw zwalniamy miejsca w bazie, potem
     * rozmawiamy ze Stripe'em. Gdyby proces padł w połowie, zostaje
     * nieanulowana płatność — a tę obsłuży webhook: rezerwacja jest już
     * wygaszona, więc autoryzacja zostanie anulowana przy zdarzeniu.
     */
    public function expireAbandoned(int $limit = 100): int
    {
        $expired = 0;

        foreach ($this->bookings->dueForExpiry($limit) as $booking) {
            if (! $this->bookings->expire($booking)) {
                // Webhook nas wyprzedził — rezerwacja nie jest już pending.
                continue;
            }

            $expired++;

            if ($booking->stripe_payment_intent_id === null) {
                continue;
            }

            try {
                $this->gateway->cancelIntent(
                    $booking->stripe_payment_intent_id,
                    $this->key($booking, 'cancel'),
                );
            } catch (PaymentRejectedException $e) {
                // Płatność mogła w międzyczasie dojść do skutku (BLIK
                // potwierdza się w sekundy). Nie przerywamy przebiegu:
                // zdarzenie succeeded trafi na wygaszoną rezerwację
                // i uruchomi zwrot.
                Log::warning('Nie udało się anulować płatności wygasłej rezerwacji.', [
                    'booking' => $booking->reference,
                    'code' => $e->providerCode,
                ]);
            }
        }

        return $expired;
    }
}
