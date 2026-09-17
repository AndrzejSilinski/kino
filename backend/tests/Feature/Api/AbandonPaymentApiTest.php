<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\PaymentRejectedException;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Rezygnacja klienta z rozpoczętej płatności: DELETE /bookings/{ref}/payment (Etap 8, blok H1).
 *
 * Sprawdzamy skutki, na których polega klient: miejsca wracają do sprzedaży od razu,
 * płatność u operatora zostaje anulowana tym samym kluczem idempotencji co przy
 * wygaśnięciu, a opłaconej rezerwacji ta ścieżka nie rusza.
 */
final class AbandonPaymentApiTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'JJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJ';

    private FakePaymentGateway $gateway;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->owner = User::factory()->create();

        $this->createScreeningWithSeats(rows: 1, cols: 4);
        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::factory()->create([
                'screening_id' => $this->screening->id,
                'price_category_id' => $categoryId,
                'price' => 2500,
            ]);
        }
    }

    /** Checkout właściciela przez HTTP; zwraca numer rezerwacji. */
    private function startPayment(): string
    {
        Sanctum::actingAs($this->owner);
        $headers = ['X-Session-Id' => self::SESSION];
        $this->withHeaders($headers)
            ->postJson('/api/v1/screenings/'.$this->screening->id.'/seat-locks', ['seat_ids' => [$this->seatIds[0]]])
            ->assertCreated();

        return (string) $this->withHeaders($headers)
            ->postJson('/api/v1/screenings/'.$this->screening->id.'/booking')
            ->assertCreated()
            ->json('data.booking.reference');
    }

    private function abandon(string $reference): TestResponse
    {
        return $this->deleteJson('/api/v1/bookings/'.$reference.'/payment');
    }

    private function seatStatus(): string
    {
        return (string) collect($this->getJson('/api/v1/screenings/'.$this->screening->id.'/seat-map')->json('data.seats'))
            ->firstWhere('id', $this->seatIds[0])['status'];
    }

    public function test_owner_abandons_payment_seats_return_and_intent_is_cancelled(): void
    {
        $reference = $this->startPayment();

        $this->abandon($reference)->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('free', $this->seatStatus());
        $this->assertSame(['create', 'cancel'], $this->gateway->operations());
        $this->assertSame('booking:'.$reference.':cancel', $this->gateway->keys()[1]);
        $this->withHeader('X-Session-Id', self::SESSION)
            ->getJson('/api/v1/screenings/'.$this->screening->id.'/seat-locks')
            ->assertJsonPath('data.seats_count', 0)
            ->assertJsonPath('data.pending_booking', null);
    }

    public function test_repeated_abandon_is_idempotent_and_does_not_call_provider_again(): void
    {
        $reference = $this->startPayment();

        $this->abandon($reference)->assertOk();
        $this->abandon($reference)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(['create', 'cancel'], $this->gateway->operations());
    }

    public function test_provider_failure_does_not_undo_released_seats(): void
    {
        $reference = $this->startPayment();
        $this->gateway->failNext('cancel', new PaymentRejectedException('payment_intent_unexpected_state'));

        $this->abandon($reference)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('free', $this->seatStatus());
    }

    public function test_paid_booking_is_not_abandoned(): void
    {
        $booking = Booking::factory()->paid()->create(['user_id' => $this->owner->id, 'screening_id' => $this->screening->id]);
        Sanctum::actingAs($this->owner);

        $this->abandon($booking->reference)->assertStatus(409)
            ->assertJsonPath('code', 'BOOKING_NOT_PAYABLE')
            ->assertJsonPath('context.booking_status', 'paid');
        $this->assertSame([], $this->gateway->operations());
    }

    public function test_only_the_owner_can_abandon(): void
    {
        $reference = $this->startPayment();

        $this->app['auth']->forgetGuards();
        $this->abandon($reference)->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->abandon($reference)->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame('pending', Booking::query()->where('reference', $reference)->value('status')->value);
    }
}
