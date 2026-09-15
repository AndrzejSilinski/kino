<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
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
use Illuminate\Support\Facades\Queue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Wyścig przy płatności — świadomy element zadania.
 *
 * Scenariusz: klient jest u operatora płatności, a jego blokada w tym
 * czasie przepada i miejsce bierze ktoś inny. System ma NIE pobrać
 * pieniędzy za miejsce, którego już nie ma.
 */
class PaymentRaceTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_SESSION = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka Q: bez tego udana płatność generowałaby PDF i mail w kolejce sync.
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

    public function test_utrata_miejsca_w_trakcie_platnosci_nie_konczy_sie_pobraniem_pieniedzy(): void
    {
        $booking = $this->pendingBooking();

        // Blokada rezerwacji przepada (scheduler po terminie), a miejsce
        // bierze inna sesja — dokładnie ta sytuacja z opisu zadania.
        SeatLock::query()->where('booking_id', $booking->id)
            ->update(['released_at' => CarbonImmutable::now()]);
        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::OTHER_SESSION);

        $outcome = app(PaymentService::class)->handleEvent($this->authorizedEvent($booking));

        $this->assertSame('seats_lost_canceled', $outcome);

        $booking->refresh();
        $this->assertSame(BookingStatus::Expired, $booking->status);
        $this->assertCount(0, $booking->tickets()->get());

        // NAJWAŻNIEJSZA ASERCJA CAŁEGO ETAPU: autoryzacja anulowana,
        // pobranie nie nastąpiło. Klient nie zobaczy obciążenia.
        $this->assertSame(['cancel'], $this->gateway->operations());
    }

    public function test_platnosc_po_terminie_jest_honorowana_gdy_miejsca_nadal_sa_nasze(): void
    {
        $booking = $this->pendingBooking();

        // Termin minął, ale nikt nie zwolnił blokad: wygasła, niezwolniona
        // blokada wciąż zajmuje indeks częściowy, więc miejsce jest nasze.
        SeatLock::query()->where('booking_id', $booking->id)
            ->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
        $booking->expires_at = CarbonImmutable::now()->subMinute();
        $booking->save();

        $outcome = app(PaymentService::class)->handleEvent($this->authorizedEvent($booking));

        $this->assertSame('tickets_issued', $outcome);
        $this->assertCount(count($this->seatIds), $booking->refresh()->tickets()->get());
        $this->assertSame(['capture'], $this->gateway->operations());
    }

    public function test_wygaszenie_rezerwacji_zwalnia_miejsca_i_anuluje_platnosc(): void
    {
        $booking = $this->pendingBooking();

        $booking->expires_at = CarbonImmutable::now()->subSecond();
        $booking->save();

        $expired = app(PaymentService::class)->expireAbandoned();

        $this->assertSame(1, $expired);
        $this->assertSame(BookingStatus::Expired, $booking->refresh()->status);
        $this->assertSame(['cancel'], $this->gateway->operations());

        // Miejsca wróciły do puli: inna sesja może je zablokować.
        $locks = app(SeatLockService::class)
            ->lock($this->screening, $this->seatIds, self::OTHER_SESSION);
        $this->assertCount(count($this->seatIds), $locks);
    }

    public function test_nieudana_proba_platnosci_nie_zwalnia_miejsc(): void
    {
        $booking = $this->pendingBooking();

        $event = new WebhookEventData(
            id: 'evt_failed',
            type: 'payment_intent.payment_failed',
            createdAt: CarbonImmutable::now(),
            intent: $this->intentFor($booking, PaymentIntentStatus::RequiresPaymentMethod),
        );

        $this->assertSame('payment_failed', app(PaymentService::class)->handleEvent($event));

        // Literówka w CVC nie może kosztować klienta miejsc: rezerwacja
        // zostaje pending do końca okna płatności.
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
        $this->assertSame(
            count($this->seatIds),
            SeatLock::query()->where('booking_id', $booking->id)->whereNull('released_at')->count(),
        );
        $this->assertSame([], $this->gateway->operations());
    }

    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::SESSION, $user->id);
        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

        $booking->stripe_payment_intent_id = 'pi_test_'.$booking->id;
        $booking->save();

        return $booking;
    }

    private function authorizedEvent(Booking $booking): WebhookEventData
    {
        return new WebhookEventData(
            id: 'evt_'.$booking->id,
            type: 'payment_intent.amount_capturable_updated',
            createdAt: CarbonImmutable::now(),
            intent: $this->intentFor($booking, PaymentIntentStatus::RequiresCapture),
        );
    }

    private function intentFor(Booking $booking, PaymentIntentStatus $status): PaymentIntentData
    {
        $amount = (int) $booking->total_amount;

        return new PaymentIntentData(
            id: (string) $booking->stripe_payment_intent_id,
            status: $status,
            amountMinor: $amount,
            amountCapturableMinor: $status === PaymentIntentStatus::RequiresCapture ? $amount : 0,
            amountReceivedMinor: 0,
            currency: 'PLN',
            bookingReference: $booking->reference,
        );
    }
}
