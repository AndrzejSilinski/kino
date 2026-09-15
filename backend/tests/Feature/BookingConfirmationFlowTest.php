<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\BookingPaid;
use App\Jobs\GenerateBookingTicketsPdf;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\PaymentIntentStatus;
use App\Payments\WebhookEventData;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Kiedy płatność ogłasza "rezerwacja opłacona" (decyzje 72 i 73).
 *
 * Testujemy zdarzenie BookingPaid, a nie liczbę zadań w kolejce. Zadanie
 * PDF jest ShouldBeUnique — gdyby logika ogłosiła opłacenie dwa razy,
 * blokada unikalności i tak wpuściłaby do kolejki jedno zadanie, a test
 * liczący zadania przeszedłby mimo błędu.
 */
class BookingConfirmationFlowTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'cccccccccccccccccccccccccccccccc';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['payments.stripe.secret_key' => 'sk_test_atrapa']);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats();

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    public function test_oplacenie_jest_oglaszane_dopiero_po_pobraniu_pieniedzy(): void
    {
        $booking = $this->pendingBooking();
        $operationsAtAnnouncement = null;

        // Zdarzenia NIE podrabiamy: słuchacz zapisuje, co bramka zrobiła do
        // chwili ogłoszenia, a prawdziwy QueueBookingConfirmation też się wykonuje.
        Event::listen(BookingPaid::class, function () use (&$operationsAtAnnouncement): void {
            $operationsAtAnnouncement = $this->gateway->operations();
        });

        $outcome = app(PaymentService::class)->handleEvent($this->event($booking, 'payment_intent.amount_capturable_updated'));

        $this->assertSame('tickets_issued', $outcome);
        $this->assertSame(['capture'], $operationsAtAnnouncement, 'Ogłoszenie przed capture wysłałoby bilety bez pieniędzy.');
        // Słuchacz odkryty automatycznie wstawił zadanie PDF do kolejki.
        Queue::assertPushed(GenerateBookingTicketsPdf::class, fn (GenerateBookingTicketsPdf $job): bool => $job->bookingId === $booking->id);
    }

    public function test_echo_succeeded_po_capture_nie_oglasza_drugi_raz(): void
    {
        Event::fake([BookingPaid::class]);
        $booking = $this->pendingBooking();
        $payments = app(PaymentService::class);

        $payments->handleEvent($this->event($booking, 'payment_intent.amount_capturable_updated'));
        $outcome = $payments->handleEvent($this->event($booking->refresh(), 'payment_intent.succeeded', PaymentIntentStatus::Succeeded));

        $this->assertSame('already_paid', $outcome);
        Event::assertDispatchedTimes(BookingPaid::class, 1);
    }

    public function test_ponowienie_po_awarii_capture_oglasza_oplacenie(): void
    {
        Event::fake([BookingPaid::class]);
        $booking = $this->pendingBooking();

        // Bilety wystawione, ale proces padł przed capture: rezerwacja jest
        // już paid, a Stripe ponawia zdarzenie autoryzacji.
        app(BookingService::class)->fulfil($booking);
        $outcome = app(PaymentService::class)->handleEvent($this->event($booking->refresh(), 'payment_intent.amount_capturable_updated'));

        $this->assertSame('captured', $outcome);
        Event::assertDispatched(BookingPaid::class, fn (BookingPaid $event): bool => $event->bookingId === $booking->id);
    }

    public function test_platnosc_bez_recznego_capture_blik_oglasza_oplacenie(): void
    {
        Event::fake([BookingPaid::class]);
        $booking = $this->pendingBooking();

        $outcome = app(PaymentService::class)->handleEvent($this->event($booking, 'payment_intent.succeeded', PaymentIntentStatus::Succeeded));

        $this->assertSame('tickets_issued', $outcome);
        Event::assertDispatchedTimes(BookingPaid::class, 1);
    }

    public function test_nieudana_proba_platnosci_nie_oglasza_oplacenia(): void
    {
        Event::fake([BookingPaid::class]);
        $booking = $this->pendingBooking();

        $outcome = app(PaymentService::class)->handleEvent($this->event($booking, 'payment_intent.payment_failed', PaymentIntentStatus::RequiresPaymentMethod));

        $this->assertSame('payment_failed', $outcome);
        Event::assertNotDispatched(BookingPaid::class);
    }

    public function test_utrata_miejsc_nie_oglasza_oplacenia(): void
    {
        Event::fake([BookingPaid::class]);
        $booking = $this->pendingBooking();
        SeatLock::query()->where('booking_id', $booking->id)->update(['released_at' => CarbonImmutable::now()]);

        $outcome = app(PaymentService::class)->handleEvent($this->event($booking, 'payment_intent.amount_capturable_updated'));

        $this->assertSame('seats_lost_canceled', $outcome);
        Event::assertNotDispatched(BookingPaid::class);
    }

    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::SESSION, $user->id);
        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

        $booking->stripe_payment_intent_id = 'pi_flow_'.$booking->id;
        $booking->save();

        return $booking;
    }

    private function event(Booking $booking, string $type, PaymentIntentStatus $status = PaymentIntentStatus::RequiresCapture): WebhookEventData
    {
        $amount = (int) $booking->total_amount;

        return new WebhookEventData(
            id: 'evt_'.$type.'_'.$booking->id,
            type: $type,
            createdAt: CarbonImmutable::now(),
            intent: new PaymentIntentData(
                id: (string) $booking->stripe_payment_intent_id,
                status: $status,
                amountMinor: $amount,
                amountCapturableMinor: $status === PaymentIntentStatus::RequiresCapture ? $amount : 0,
                amountReceivedMinor: $status === PaymentIntentStatus::Succeeded ? $amount : 0,
                currency: 'PLN',
                bookingReference: $booking->reference,
            ),
        );
    }
}
