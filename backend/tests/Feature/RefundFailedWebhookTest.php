<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Events\SalesActivity;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentStatus;
use App\Payments\RefundData;
use App\Payments\WebhookEventData;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatLockService;
use App\Support\Labels;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Nieudany zwrot: zdarzenie refund.failed (Etap 10, blok C).
 *
 * Operator przyjmuje zwrot, rezerwacja dostaje status "zwrócona", a dopiero potem bank
 * albo karta go odrzuca. Najważniejsze asercje dotyczą tego, czego system NIE robi:
 * nie ponawia zwrotu sam (karta zamknięta odrzuci każdą próbę), nie zostawia klientowi
 * statusu "zwrócona" i nie pozwala późniejszemu zapisowi przyjęcia nadpisać porażki.
 */
final class RefundFailedWebhookTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const SESSION = 'cccccccccccccccccccccccccccccccc';

    private const REASON = 'Awaria nagłośnienia, seans odwołany.';

    private FakePaymentGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config([
            'payments.stripe.webhook_secret' => self::SECRET,
            'payments.stripe.secret_key' => 'sk_test_x',
        ]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats(1, 2);
        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
        $this->admin = User::factory()->admin()->create();
    }

    public function test_nieudany_zwrot_cofa_status_zapisuje_powod_i_nie_jest_ponawiany(): void
    {
        $booking = $this->refundedBooking();
        $completedAt = $booking->refund_completed_at;
        Event::fake([BookingStatusChanged::class, SalesActivity::class]);

        $outcome = $this->payments()->handleEvent($this->refundEvent($booking, 'expired_or_canceled_card'));

        $this->assertSame('refund_failed', $outcome);
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->refund_failed_at);
        $this->assertSame('expired_or_canceled_card', $booking->refund_failure_reason);
        $this->assertTrue($booking->refund_completed_at->equalTo($completedAt), 'data przyjęcia zwrotu zostaje');

        // Klient i feed sprzedaży widzą cofnięcie "zwrócona" -> "anulowana".
        Event::assertDispatched(BookingStatusChanged::class);
        Event::assertDispatched(SalesActivity::class);

        // Żadnego ponownego zwrotu: ani od razu, ani z harmonogramu po czasie.
        $this->travel(PaymentService::REFUND_RETRY_AFTER_SECONDS + 60)->seconds();
        $this->payments()->retryRefunds();
        $this->assertSame(['refund'], $this->gateway->operations());
    }

    public function test_drugie_zdarzenie_o_tym_samym_niepowodzeniu_niczego_nie_zmienia(): void
    {
        $booking = $this->refundedBooking();
        $this->payments()->handleEvent($this->refundEvent($booking, 'declined'));
        $failedAt = $booking->refresh()->refund_failed_at;

        $this->travel(5)->minutes();
        $outcome = $this->payments()->handleEvent($this->refundEvent($booking, 'lost_or_stolen_card', 'evt_drugie'));

        $this->assertSame('refund_failure_known', $outcome);
        $booking->refresh();
        $this->assertTrue($booking->refund_failed_at->equalTo($failedAt));
        $this->assertSame('declined', $booking->refund_failure_reason);
    }

    public function test_niepowodzenie_przed_zapisem_przyjecia_zwrotu_nie_zostaje_nadpisane(): void
    {
        // Krok bazodanowy anulowania jest zapisany, rozmowa z operatorem jeszcze się nie skończyła.
        $booking = $this->paidBooking();
        app(BookingService::class)->cancelByAdmin($booking, $this->admin, self::REASON);
        $this->assertNull($booking->refresh()->refund_completed_at);

        $this->assertSame('refund_failed', $this->payments()->handleEvent($this->refundEvent($booking, 'declined')));

        // Zapis przyjęcia zwrotu, który przychodzi później, nie może zamienić porażki w "zwrócona".
        $this->assertFalse(app(BookingService::class)->completeRefund($booking, true));
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->refund_completed_at, 'rozliczenie zamknięte jako nieudane');

        $this->travel(PaymentService::REFUND_RETRY_AFTER_SECONDS + 60)->seconds();
        $this->payments()->retryRefunds();
        $this->assertSame([], $this->gateway->operations());
    }

    public function test_zwrot_niezlecony_przez_kino_i_zdarzenia_obce_sa_tylko_odnotowane(): void
    {
        $booking = $this->paidBooking();

        $this->assertSame('refund_not_requested', $this->payments()->handleEvent($this->refundEvent($booking, 'declined')));
        $this->assertSame(BookingStatus::Paid, $booking->refresh()->status);
        $this->assertNull($booking->refund_failed_at);

        $obcy = new WebhookEventData('evt_obcy', 'refund.failed', CarbonImmutable::now(),
            refund: new RefundData('re_obcy', 'pi_spoza_systemu', true, 'declined'));
        $this->assertSame('unknown_booking', $this->payments()->handleEvent($obcy));

        $udany = new WebhookEventData('evt_udany', 'refund.failed', CarbonImmutable::now(),
            refund: new RefundData('re_udany', (string) $booking->stripe_payment_intent_id, false));
        $this->assertSame('ignored', $this->payments()->handleEvent($udany));
    }

    public function test_podpisany_webhook_przechodzi_przez_prawdziwy_adapter_stripe(): void
    {
        $booking = $this->refundedBooking();

        $this->sendWebhook($this->refundPayload('evt_re_1', 're_1', (string) $booking->stripe_payment_intent_id, 'lost_or_stolen_card'))
            ->assertOk()
            ->assertJsonPath('data.outcome', 'refund_failed');

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame('lost_or_stolen_card', $booking->refund_failure_reason);

        // Dziennik zdarzeń zna płatność i rezerwację także dla zdarzenia o obiekcie zwrotu.
        $logged = StripeWebhookEvent::query()->findOrFail('evt_re_1');
        $this->assertSame($booking->stripe_payment_intent_id, $logged->payment_intent_id);
        $this->assertSame($booking->id, $logged->booking_id);

        // payment_intent rozwinięty do obiektu (expand) — ten sam identyfikator.
        $this->sendWebhook($this->refundPayload('evt_re_2', 're_1', ['id' => $booking->stripe_payment_intent_id, 'object' => 'payment_intent'], 'lost_or_stolen_card'))
            ->assertOk()
            ->assertJsonPath('data.outcome', 'refund_failure_known');
    }

    public function test_panel_pokazuje_nieudany_zwrot_z_powodem_po_polsku(): void
    {
        $booking = $this->refundedBooking();
        $this->payments()->handleEvent($this->refundEvent($booking, 'lost_or_stolen_card'));

        $this->actingAs($this->admin, 'web')->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('zwrot NIEUDANY')
            ->assertSee('karta zgłoszona jako zgubiona lub skradziona');

        $this->assertSame('kod operatora: nowy_kod', Labels::refundFailureReason('nowy_kod'));
        $this->assertSame('operator nie podał przyczyny', Labels::refundFailureReason(null));
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function paidBooking(): Booking
    {
        $user = User::factory()->create();
        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::SESSION, $user->id);
        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);
        $booking->stripe_payment_intent_id = 'pi_test_'.$booking->id;
        $booking->save();
        app(BookingService::class)->fulfil($booking);
        $this->gateway->setIntent($booking->stripe_payment_intent_id, PaymentIntentStatus::Succeeded, (int) $booking->total_amount, $booking->reference);

        return $booking->refresh();
    }

    /** Opłacona, anulowana przez kino, zwrot przyjęty przez operatora: status "zwrócona". */
    private function refundedBooking(): Booking
    {
        $booking = $this->paidBooking();
        $this->payments()->cancelByAdmin($booking, $this->admin, self::REASON);
        $booking->refresh();
        $this->assertSame(BookingStatus::Refunded, $booking->status);
        $this->assertSame(['refund'], $this->gateway->operations());

        return $booking;
    }

    private function refundEvent(Booking $booking, string $reason, string $eventId = 'evt_pierwsze'): WebhookEventData
    {
        return new WebhookEventData(
            id: $eventId,
            type: 'refund.failed',
            createdAt: CarbonImmutable::now(),
            refund: new RefundData('re_'.$booking->id, (string) $booking->stripe_payment_intent_id, true, $reason),
        );
    }

    /** @param  string|array<string, string>  $intent */
    private function refundPayload(string $eventId, string $refundId, string|array $intent, string $reason): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'created' => time(),
            'type' => 'refund.failed',
            'data' => ['object' => [
                'id' => $refundId,
                'object' => 'refund',
                'status' => 'failed',
                'failure_reason' => $reason,
                'payment_intent' => $intent,
                'amount' => 5000,
                'currency' => 'pln',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function sendWebhook(string $payload): TestResponse
    {
        $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, self::SECRET);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }
}
