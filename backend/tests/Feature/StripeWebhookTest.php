<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Services\BookingService;
use App\Services\SeatLockService;
use App\Events\BookingPaid;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Webhook Stripe'a: weryfikacja podpisu i idempotencja.
 *
 * Testy jadą przez PRAWDZIWY endpoint HTTP, więc obejmują też middleware
 * i kształt błędu z ApiExceptionRenderer. Podmieniona jest wyłącznie
 * bramka płatności — i to tylko w części pieniężnej.
 */
class StripeWebhookTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka Q: phpunit.xml ma QUEUE_CONNECTION=sync, więc bez tego każda
        // płatność w teście generowałaby prawdziwy PDF i wysyłała mail.
        Queue::fake();

        // Klucze testowe ustawiamy w konfiguracji, nie w .env: test ma być
        // niezależny od tego, co akurat ma w pliku osoba go uruchamiająca.
        config([
            'payments.stripe.webhook_secret' => self::SECRET,
            'payments.stripe.secret_key' => 'sk_test_atrapa',
        ]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats();
        $this->ensurePrices();
    }

    public function test_podrobiony_podpis_jest_odrzucany(): void
    {
        $payload = $this->eventPayload('evt_1', 'payment_intent.succeeded', 'pi_1', 1000, 'REF');

        $response = $this->sendWebhook($payload, 't='.time().',v1='.str_repeat('0', 64));

        $response->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');

        // Zdarzenie o złym podpisie nie zostawia śladu w dzienniku ani
        // nie dotyka płatności — kontroler w ogóle się nie wykonał.
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertSame([], $this->gateway->operations());
    }

    public function test_brak_naglowka_z_podpisem_jest_odrzucany(): void
    {
        $payload = $this->eventPayload('evt_2', 'payment_intent.succeeded', 'pi_2', 1000, 'REF');

        $this->sendWebhook($payload, '')
            ->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    public function test_przeterminowany_znacznik_czasu_jest_odrzucany(): void
    {
        $payload = $this->eventPayload('evt_3', 'payment_intent.succeeded', 'pi_3', 1000, 'REF');

        // Podpis poprawny, ale sprzed godziny: tolerancja wynosi 300 s.
        // To jest ochrona przed powtórzeniem przechwyconego żądania.
        $old = time() - 3600;
        $signature = 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$payload, self::SECRET);

        $this->sendWebhook($payload, $signature)
            ->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    public function test_autoryzacja_karty_wystawia_bilety_i_pobiera_platnosc(): void
    {
        $booking = $this->pendingBooking('pi_ok');

        $payload = $this->eventPayload(
            'evt_ok',
            'payment_intent.amount_capturable_updated',
            'pi_ok',
            (int) $booking->total_amount,
            $booking->reference,
        );

        $this->sendWebhook($payload)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'tickets_issued');

        $booking->refresh();

        $this->assertSame(BookingStatus::Paid, $booking->status);
        $this->assertCount(count($this->seatIds), $booking->tickets()->get());
        $this->assertNotNull($booking->paid_at);
        // Pieniądze pobrane DOPIERO po wystawieniu biletów.
        $this->assertSame(['capture'], $this->gateway->operations());
    }

    public function test_powtorzone_zdarzenie_nie_tworzy_duplikatow_biletow(): void
    {
        $booking = $this->pendingBooking('pi_dup');
        Event::fake([BookingPaid::class]);

        $payload = $this->eventPayload(
            'evt_dup',
            'payment_intent.amount_capturable_updated',
            'pi_dup',
            (int) $booking->total_amount,
            $booking->reference,
        );

        $this->sendWebhook($payload)->assertOk()->assertJsonPath('data.duplicate', false);
        $this->sendWebhook($payload)->assertOk()->assertJsonPath('data.duplicate', true);

        // Sedno wymagania z zadania: to samo zdarzenie dwa razy,
        // a bilety dokładnie raz.
        $this->assertCount(count($this->seatIds), $booking->tickets()->get());
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        // Powtórzone zdarzenie nie ogłasza opłacenia drugi raz: jeden PDF i jeden mail.
        Event::assertDispatchedTimes(BookingPaid::class, 1);
        $this->assertSame(['capture'], $this->gateway->operations());
    }

    private function sendWebhook(string $payload, ?string $signature = null): TestResponse
    {
        $signature ??= 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, self::SECRET);

        // call() zamiast postJson(): potrzebujemy SUROWEGO ciała żądania,
        // bo podpis liczy się z bajtów, a nie z tablicy po dekodowaniu.
        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }

    private function eventPayload(
        string $eventId,
        string $type,
        string $intentId,
        int $amount,
        string $reference,
        string $status = 'requires_capture',
    ): string {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'created' => time(),
            'type' => $type,
            'data' => ['object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'status' => $status,
                'amount' => $amount,
                'amount_capturable' => $status === 'requires_capture' ? $amount : 0,
                'amount_received' => $status === 'succeeded' ? $amount : 0,
                'currency' => 'pln',
                'metadata' => ['booking_reference' => $reference],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function pendingBooking(string $intentId): Booking
    {
        $user = User::factory()->create();

        app(SeatLockService::class)->lock($this->screening, $this->seatIds, self::SESSION, $user->id);
        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

        $booking->stripe_payment_intent_id = $intentId;
        $booking->save();

        return $booking;
    }

    /** Cennik seansu dla wszystkich kategorii występujących w sali. */
    private function ensurePrices(int $price = 2500): void
    {
        $categoryIds = Seat::query()
            ->whereIn('id', $this->seatIds)
            ->pluck('price_category_id')
            ->unique();

        foreach ($categoryIds as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => $price],
            );
        }
    }
}
