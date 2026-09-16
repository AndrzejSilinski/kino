<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Events\BookingPaid;
use App\Exceptions\BookingCancellationException;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Exceptions\PaymentRejectedException;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\User;
use App\Notifications\BookingCancelledByCinema;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\PaymentIntentStatus;
use App\Payments\RefundOutcome;
use App\Payments\WebhookEventData;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    /** Kod błędu Stripe'a dla zwrotu, który już się odbył — traktujemy jak sukces. */
    public const PROVIDER_ALREADY_REFUNDED = 'charge_already_refunded';

    /** Komenda ponawiająca pomija rozliczenia młodsze niż tyle sekund (panel rozlicza je sam). */
    public const REFUND_RETRY_AFTER_SECONDS = 120;

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

        if ($booking->refund_requested_at !== null) {
            // Anulowana przez administratora (Etap 7, blok K). Płatność rozlicza
            // settleRefund() na podstawie BIEŻĄCEGO stanu u operatora, a nie treści
            // zdarzenia, które mogło przyjść z opóźnieniem albo nie po kolei.
            // Bez tej gałęzi spóźnione amount_capturable_updated pobrałoby pieniądze
            // za anulowaną rezerwację, a succeeded zleciłoby drugi zwrot.
            return 'admin_cancelled';
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
     * Anulowanie rezerwacji przez administratora (Etap 7, blok K).
     *
     * Krok 1 — BookingService::cancelByAdmin(): transakcja w bazie, miejsca wracają
     *          do sprzedaży NATYCHMIAST, niezależnie od operatora płatności.
     * Krok 2 — settleRefund(): po COMMIT, rozmowa z operatorem.
     * Krok 3 — BookingService::completeRefund(): zapis wyniku.
     *
     * Porażka kroku 2 nie cofa kroku 1: rozliczenie zostaje na liście zaległych
     * (refund_requested_at bez refund_completed_at) i ponowi je komenda.
     *
     * @throws BookingCancellationException
     */
    public function cancelByAdmin(Booking $booking, User $admin, string $reason): RefundOutcome
    {
        $cancelled = $this->bookings->cancelByAdmin($booking, $admin, $reason);

        $outcome = $cancelled->refund_requested_at === null
            ? RefundOutcome::NotRequired
            : $this->settleRefund($cancelled);

        // Mail na końcu: najpierw pieniądze, potem powiadomienie (jak decyzja 72).
        // Awaria kolejki nie może zamienić udanego anulowania w błąd panelu.
        try {
            $cancelled->user()->firstOrFail()->notify(new BookingCancelledByCinema((int) $cancelled->id));
        } catch (Throwable $e) {
            report($e);
        }

        return $outcome;
    }

    /**
     * Rozliczenie płatności anulowanej rezerwacji — decyzja z BIEŻĄCEGO stanu u operatora.
     *
     * Stan odczytujemy (retrieveIntent), zamiast zgadywać z naszej bazy: status paid
     * nie mówi, czy capture już się odbył (fulfil ustawia paid PRZED capture).
     *
     *   da się anulować (w tym requires_capture) -> cancelIntent, klucz "cancel"
     *   canceled                                -> nic do zrobienia
     *   succeeded                               -> refundIntent, klucz "refund"
     *   processing / nieznany                   -> zostaw do ponowienia
     *
     * Klucze są TE SAME co w obsłudze webhooków i wygaszaniu, więc równoległe
     * wywołania (panel, komenda, webhook sprzed anulowania) nie zrobią dwóch zwrotów.
     * Klucz idempotencji Stripe'a żyje 24 godziny — na później zostaje kod
     * charge_already_refunded, który też oznacza sukces.
     */
    public function settleRefund(Booking $booking): RefundOutcome
    {
        if ($booking->refund_requested_at === null || $booking->stripe_payment_intent_id === null) {
            return RefundOutcome::NotRequired;
        }

        $intentId = (string) $booking->stripe_payment_intent_id;

        try {
            $intent = $this->gateway->retrieveIntent($intentId);

            if ($intent->status->isCancelable()) {
                $this->gateway->cancelIntent($intentId, $this->key($booking, 'cancel'));
                $moneyReturned = false;
            } elseif ($intent->status === PaymentIntentStatus::Canceled) {
                $moneyReturned = false;
            } elseif ($intent->status === PaymentIntentStatus::Succeeded) {
                $this->refund($booking, $intentId);
                $moneyReturned = true;
            } else {
                Log::info('Rozliczenie anulowanej rezerwacji odłożone: płatność w toku.', [
                    'booking' => $booking->reference,
                    'intent_status' => $intent->status->value,
                ]);

                return RefundOutcome::Pending;
            }
        } catch (PaymentProviderUnavailableException|PaymentRejectedException $e) {
            // Najczęściej: capture wyprzedził nasze anulowanie autoryzacji. Następna
            // próba odczyta succeeded i zleci zwrot.
            Log::warning('Rozliczenie anulowanej rezerwacji nie powiodło się; ponowi je harmonogram.', [
                'booking' => $booking->reference,
                'code' => $e->errorCode(),
                'provider_code' => $e instanceof PaymentRejectedException ? $e->providerCode : null,
            ]);

            return RefundOutcome::Pending;
        }

        $this->bookings->completeRefund($booking, $moneyReturned);

        return $moneyReturned ? RefundOutcome::Refunded : RefundOutcome::Voided;
    }

    /**
     * Ponawia zaległe rozliczenia. Wejście dla schedulera.
     *
     * @return array<string, int> liczba rezerwacji według wyniku (RefundOutcome)
     */
    public function retryRefunds(int $limit = 50): array
    {
        $counts = array_fill_keys(array_column(RefundOutcome::cases(), 'value'), 0);

        foreach ($this->bookings->dueForRefundRetry($limit, self::REFUND_RETRY_AFTER_SECONDS) as $booking) {
            $counts[$this->settleRefund($booking)->value]++;
        }

        return $counts;
    }

    private function refund(Booking $booking, string $intentId): void
    {
        try {
            $this->gateway->refundIntent($intentId, $this->key($booking, 'refund'));
        } catch (PaymentRejectedException $e) {
            if ($e->providerCode !== self::PROVIDER_ALREADY_REFUNDED) {
                throw $e;
            }
        }
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
