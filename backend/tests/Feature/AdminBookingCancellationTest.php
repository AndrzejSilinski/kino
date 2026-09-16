<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BookingCancellationException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Exceptions\PaymentRejectedException;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\BookingCancelledByCinema;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\PaymentIntentStatus;
use App\Payments\RefundOutcome;
use App\Payments\WebhookEventData;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatLockService;
use App\Services\SeatStateRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Anulowanie rezerwacji przez administratora ze zwrotem (Etap 7, blok K).
 *
 * Atrapa bramki pozwala sprawdzić NIE TYLKO bazę, ale też to, co poszło do operatora:
 * capture czy void, jaki klucz idempotencji, ile razy. Najważniejsze asercje tego pliku
 * dotyczą pieniędzy: żadnego pobrania po anulowaniu i żadnego podwójnego zwrotu.
 */
final class AdminBookingCancellationTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_SESSION = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const REASON = '  Awaria projektora w sali, seans przeniesiony.  ';

    private FakePaymentGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka Q: bez tego opłacenie generowałoby PDF i mail w kolejce sync.
        Queue::fake();
        Notification::fake();

        config(['payments.stripe.secret_key' => 'sk_test_atrapa']);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats(1, 4);
        $this->admin = User::factory()->admin()->create();

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    // ─── Opłacona: zwrot albo zwolnienie autoryzacji ─────────────────────

    public function test_paid_and_captured_booking_is_refunded_seats_return_and_customer_is_notified(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::Succeeded);
        $versionBefore = app(SeatStateRecorder::class)->currentVersion($this->screening->id);

        $outcome = $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON);

        // Plan sali u klientów dostaje nową wersję (seats.changed: wolne) — jedną na anulowanie.
        $this->assertSame($versionBefore + 1, app(SeatStateRecorder::class)->currentVersion($this->screening->id));

        $this->assertSame(RefundOutcome::Refunded, $outcome);
        $booking->refresh();
        $this->assertSame(BookingStatus::Refunded, $booking->status);
        $this->assertSame(trim(self::REASON), $booking->cancellation_reason);
        $this->assertSame($this->admin->id, $booking->cancelled_by_user_id);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertNotNull($booking->refund_requested_at);
        $this->assertNotNull($booking->refund_completed_at);
        $this->assertSame([TicketStatus::Cancelled], $booking->tickets()->get()->pluck('status')->unique()->values()->all());

        // Pieniądze: jeden zwrot, klucz wspólny z webhookiem, bez capture i bez void.
        $this->assertSame(['refund'], $this->gateway->operations());
        $this->assertSame(['booking:'.$booking->reference.':refund'], $this->gateway->keys());

        // Miejsca wróciły do sprzedaży: inna sesja może je zablokować.
        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::OTHER_SESSION);
        $this->assertSame(4, SeatLock::query()->where('session_id', self::OTHER_SESSION)->whereNull('released_at')->count());

        Notification::assertSentTo($booking->user, BookingCancelledByCinema::class, function (BookingCancelledByCinema $notification) use ($booking): bool {
            $mail = $notification->toMail($booking->user);
            $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

            return str_contains($text, $booking->reference)
                && str_contains($text, 'Zwrócimy 100,00')
                && ! str_contains($text, 'projektora')
                && $notification->shouldSend($booking->user, 'mail');
        });
    }

    public function test_paid_but_not_captured_booking_releases_authorization_and_stays_cancelled(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::RequiresCapture);

        $this->assertSame(RefundOutcome::Voided, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->refund_completed_at);
        // NAJWAŻNIEJSZE: autoryzacja zwolniona, pieniądze nie zostały pobrane ani zwracane.
        $this->assertSame(['cancel'], $this->gateway->operations());
        $this->assertSame(['booking:'.$booking->reference.':cancel'], $this->gateway->keys());
    }

    // ─── Oczekująca na płatność ──────────────────────────────────────────

    public function test_pending_booking_without_payment_releases_seats_and_never_calls_provider(): void
    {
        $booking = $this->pendingBooking(withIntent: false);

        $this->assertSame(RefundOutcome::NotRequired, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNull($booking->refund_requested_at);
        $this->assertSame(0, SeatLock::query()->where('booking_id', $booking->id)->whereNull('released_at')->count());
        $this->assertSame(0, $this->gateway->retrieves);
        $this->assertSame([], $this->gateway->operations());
        Notification::assertSentTo($booking->user, BookingCancelledByCinema::class);

        // Mail "kino anulowało" tylko po anulowaniu przez administratora — nie po rezygnacji klienta.
        $byCustomer = Booking::factory()->cancelled()->create(['cancelled_by_user_id' => null]);
        $this->assertFalse((new BookingCancelledByCinema($byCustomer->id))->shouldSend($byCustomer->user, 'mail'));
        $this->assertTrue((new BookingCancelledByCinema($booking->id))->shouldSend($booking->user, 'mail'));
    }

    public function test_pending_booking_with_payment_in_progress_cancels_that_payment(): void
    {
        $booking = $this->pendingBooking(withIntent: true);
        $this->gateway->setIntent($booking->stripe_payment_intent_id, PaymentIntentStatus::RequiresAction, 10000, $booking->reference);

        $this->assertSame(RefundOutcome::Voided, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame(['cancel'], $this->gateway->operations());
    }

    // ─── Porażki operatora i ponowienia ──────────────────────────────────

    public function test_provider_outage_leaves_refund_pending_and_the_command_finishes_it_with_the_same_key(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::Succeeded);
        $this->gateway->failNext('refund', new PaymentProviderUnavailableException);

        $this->assertSame(RefundOutcome::Pending, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->refund_requested_at);
        $this->assertNull($booking->refund_completed_at);
        $this->assertSame('pending', (new BookingResource($booking))->toArray(request())['cancellation']['refund']);

        // Miejsca są wolne od razu, niezależnie od operatora.
        $this->assertSame(0, Ticket::query()->where('booking_id', $booking->id)->where('status', '!=', TicketStatus::Cancelled)->count());

        // Świeże anulowanie komenda pomija — rozlicza je panel.
        $this->artisan('cinema:bookings:retry-refunds')->expectsOutputToContain('nadal zaległe 0')->assertSuccessful();
        $this->assertSame(['refund'], $this->gateway->operations());

        $this->travelTo(CarbonImmutable::now()->addSeconds(PaymentService::REFUND_RETRY_AFTER_SECONDS + 1));
        $this->artisan('cinema:bookings:retry-refunds')->expectsOutputToContain('zwroty 1')->assertSuccessful();

        $booking->refresh();
        $this->assertSame(BookingStatus::Refunded, $booking->status);
        $this->assertNotNull($booking->refund_completed_at);
        $key = 'booking:'.$booking->reference.':refund';
        $this->assertSame([$key, $key], $this->gateway->keys());

        // Kolejny przebieg nie ma już nic do zrobienia.
        $this->artisan('cinema:bookings:retry-refunds')->expectsOutputToContain('zwroty 0')->assertSuccessful();
        $this->assertCount(2, $this->gateway->operations());
    }

    public function test_capture_that_wins_the_race_with_void_ends_in_a_refund_on_retry(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::RequiresCapture);
        // Odczyt mówi requires_capture, ale zanim dotarło anulowanie, webhook zdążył pobrać pieniądze.
        $this->gateway->failNext('cancel', new PaymentRejectedException('payment_intent_unexpected_state'));

        $this->assertSame(RefundOutcome::Pending, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));
        $this->gateway->setIntent($booking->stripe_payment_intent_id, PaymentIntentStatus::Succeeded, 10000, $booking->reference);

        $this->travelTo(CarbonImmutable::now()->addMinutes(3));
        $this->assertSame(1, $this->payments()->retryRefunds()['refunded']);

        $this->assertSame(BookingStatus::Refunded, $booking->refresh()->status);
        $this->assertSame(['cancel', 'refund'], $this->gateway->operations());
    }

    public function test_already_refunded_error_counts_as_success_and_other_rejections_are_retried(): void
    {
        $refunded = $this->paidBooking(PaymentIntentStatus::Succeeded);
        $this->gateway->failNext('refund', new PaymentRejectedException(PaymentService::PROVIDER_ALREADY_REFUNDED));

        $this->assertSame(RefundOutcome::Refunded, $this->payments()->cancelByAdmin($refunded, $this->admin, self::REASON));
        $this->assertSame(BookingStatus::Refunded, $refunded->refresh()->status);

        // Inna odmowa operatora (np. nieznana płatność) nie kończy rozliczenia.
        $other = $this->secondPaidBooking(PaymentIntentStatus::Succeeded);
        $this->gateway->failNext('retrieve', new PaymentRejectedException('resource_missing'));
        $this->assertSame(RefundOutcome::Pending, $this->payments()->cancelByAdmin($other, $this->admin, self::REASON));
        $this->assertNull($other->refresh()->refund_completed_at);
    }

    public function test_payment_still_processing_is_left_for_retry_without_touching_it(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::Processing);

        $this->assertSame(RefundOutcome::Pending, $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON));

        $this->assertSame([], $this->gateway->operations());
        $this->assertNull($booking->refresh()->refund_completed_at);
    }

    // ─── Webhooki po anulowaniu ──────────────────────────────────────────

    public function test_late_webhooks_after_admin_cancellation_neither_capture_nor_refund_again(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::RequiresCapture);
        $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON);
        $booking->refresh();

        foreach (['payment_intent.amount_capturable_updated', 'payment_intent.succeeded', 'payment_intent.canceled'] as $type) {
            $this->assertSame('admin_cancelled', $this->payments()->handleEvent($this->event($booking, $type, PaymentIntentStatus::RequiresCapture)), $type);
        }

        $this->assertSame(['cancel'], $this->gateway->operations());
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_repeated_authorization_webhook_never_captures_a_booking_whose_tickets_were_cancelled(): void
    {
        // Bilety są, ale rezerwacja nie jest już opłacona (tu: wycofana przez revoke(),
        // bez znacznika zwrotu). Wcześniej fulfil() uznawał samo istnienie biletów za
        // "już opłacone", a onAuthorized pobierał pieniądze.
        $booking = $this->paidBooking(PaymentIntentStatus::RequiresCapture);
        app(BookingService::class)->revoke($booking);
        $this->gateway->calls = [];

        $outcome = $this->payments()->handleEvent($this->event($booking->refresh(), 'payment_intent.amount_capturable_updated', PaymentIntentStatus::RequiresCapture));

        $this->assertNotContains('capture', $this->gateway->operations(), $outcome);
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    // ─── Kiedy anulowanie jest zablokowane ───────────────────────────────

    public function test_cancellation_is_refused_after_start_with_used_tickets_for_final_statuses_and_with_bad_reason(): void
    {
        $booking = $this->paidBooking(PaymentIntentStatus::Succeeded);

        $this->assertRefused('CANCELLATION_REASON_INVALID', 422, $booking, '   krótki   ');
        $this->assertRefused('CANCELLATION_REASON_INVALID', 422, $booking, str_repeat('x', 256));

        Ticket::query()->where('booking_id', $booking->id)->limit(1)->update(['status' => TicketStatus::Used]);
        $this->assertRefused('BOOKING_TICKETS_USED', 409, $booking);
        Ticket::query()->where('booking_id', $booking->id)->update(['status' => TicketStatus::Valid]);

        $this->travelTo($this->screening->starts_at->addMinute());
        $this->assertRefused('BOOKING_SCREENING_STARTED', 409, $booking);
        $this->travelBack();

        $this->assertSame(BookingStatus::Paid, $booking->refresh()->status);
        $this->assertSame([], $this->gateway->operations());
        Notification::assertNothingSent();

        // 255 znaków wielobajtowych to wciąż 255 znaków (mb_strlen, varchar(255) w PostgreSQL).
        $this->payments()->cancelByAdmin($booking, $this->admin, str_repeat('ż', 255));
        $this->assertSame(255, mb_strlen((string) $booking->refresh()->cancellation_reason));

        // Druga próba: rezerwacja ma już status końcowy. Krok 3 jest idempotentny.
        $this->assertRefused('BOOKING_NOT_CANCELLABLE', 409, $booking);
        $this->assertFalse(app(BookingService::class)->completeRefund($booking, true));
        $this->assertSame(['refund'], $this->gateway->operations());
    }

    // ─── Kolejność blokad ────────────────────────────────────────────────

    public function test_lock_order_is_booking_first_and_checkout_retry_does_not_lock_the_booking_row(): void
    {
        $booking = $this->pendingBooking(withIntent: false);
        $locking = [];
        DB::listen(function (QueryExecuted $query) use (&$locking): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                preg_match('/from "([a-z_]+)"/', $sql, $table);
                $locking[] = $table[1] ?? '?';
            }
        });

        // Podwójne kliknięcie "Zapłać": ta sama rezerwacja, bez FOR UPDATE na bookings,
        // bo transakcja trzyma już seat_locks (odwrotna kolejność = deadlock z anulowaniem).
        $again = app(BookingService::class)->checkout($this->screening, self::SESSION, $booking->user);
        $this->assertSame($booking->id, $again->id);
        $this->assertSame(['seat_locks'], array_values(array_unique($locking)));

        $locking = [];
        app(BookingService::class)->cancelByAdmin($booking, $this->admin, self::REASON);
        $this->assertSame('bookings', $locking[0] ?? null);
        $this->assertContains('seat_locks', $locking);

        $paid = $this->secondPaidBooking(PaymentIntentStatus::Succeeded);
        $locking = [];
        app(BookingService::class)->cancelByAdmin($paid, $this->admin, self::REASON);
        $this->assertSame(['bookings', 'tickets'], array_slice($locking, 0, 2));
    }

    // ─── Pomocnicze ──────────────────────────────────────────────────────

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function pendingBooking(bool $withIntent, string $session = self::SESSION): Booking
    {
        $user = User::factory()->create();

        app(SeatLockService::class)->lock($this->screening, $this->seatIds, $session, $user->id);
        $booking = app(BookingService::class)->checkout($this->screening, $session, $user);

        if ($withIntent) {
            $booking->stripe_payment_intent_id = 'pi_test_'.$booking->id;
            $booking->save();
        }

        return $booking;
    }

    private function paidBooking(PaymentIntentStatus $atProvider, string $session = self::SESSION): Booking
    {
        $booking = $this->pendingBooking(true, $session);
        app(BookingService::class)->fulfil($booking);
        $this->gateway->setIntent($booking->stripe_payment_intent_id, $atProvider, (int) $booking->total_amount, $booking->reference);

        return $booking->refresh();
    }

    /** Druga opłacona rezerwacja — na nowym seansie w nowej sali, żeby nie dzielić miejsc z pierwszą. */
    private function secondPaidBooking(PaymentIntentStatus $atProvider): Booking
    {
        $this->createScreeningWithSeats(1, 2);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }

        return $this->paidBooking($atProvider, self::OTHER_SESSION);
    }

    private function assertRefused(string $code, int $status, Booking $booking, string $reason = self::REASON): void
    {
        try {
            $this->payments()->cancelByAdmin($booking, $this->admin, $reason);
            $this->fail("Oczekiwano {$code}");
        } catch (BookingCancellationException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertSame($status, $e->status());
            $this->assertStringNotContainsString('projektora', json_encode($e->context()));
        }
    }

    private function event(Booking $booking, string $type, PaymentIntentStatus $status): WebhookEventData
    {
        $amount = (int) $booking->total_amount;

        return new WebhookEventData(
            id: 'evt_'.$booking->id.'_'.$type,
            type: $type,
            createdAt: CarbonImmutable::now(),
            intent: new PaymentIntentData(
                id: (string) $booking->stripe_payment_intent_id,
                status: $status,
                amountMinor: $amount,
                amountCapturableMinor: $status === PaymentIntentStatus::RequiresCapture ? $amount : 0,
                amountReceivedMinor: 0,
                currency: 'PLN',
                bookingReference: $booking->reference,
            ),
        );
    }
}
