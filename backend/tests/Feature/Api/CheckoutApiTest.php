<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
 * Checkout przez HTTP i to, co po nim widzi koszyk (Etap 8, blok H1).
 *
 * Kontrakt, na którym opiera się SPA: powtórzony checkout zwraca tę samą płatność
 * (F5 na ekranie płatności), koszyk mówi o rozpoczętej płatności (zamrożenie planu
 * sali), a zwolnienie wszystkiego oddaje tylko miejsca DOBRANE po checkoucie.
 */
final class CheckoutApiTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'HHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHH';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['payments.stripe.publishable_key' => 'pk_'.'test_atrapa']);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats(rows: 1, cols: 4);
        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::factory()->create([
                'screening_id' => $this->screening->id,
                'price_category_id' => $categoryId,
                'price' => 2500,
            ]);
        }
    }

    private function lock(int ...$seatIds): void
    {
        $this->withHeader('X-Session-Id', self::SESSION)
            ->postJson('/api/v1/screenings/'.$this->screening->id.'/seat-locks', ['seat_ids' => $seatIds])
            ->assertCreated();
    }

    private function checkout(): TestResponse
    {
        return $this->withHeader('X-Session-Id', self::SESSION)
            ->postJson('/api/v1/screenings/'.$this->screening->id.'/booking');
    }

    private function cart(): TestResponse
    {
        return $this->withHeader('X-Session-Id', self::SESSION)
            ->getJson('/api/v1/screenings/'.$this->screening->id.'/seat-locks');
    }

    public function test_repeated_checkout_returns_the_same_payment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->lock($this->seatIds[0]);

        $first = $this->checkout()->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.payment.provider', 'stripe');
        $second = $this->checkout()->assertOk();

        $this->assertSame($first->json('data.booking.reference'), $second->json('data.booking.reference'));
        $this->assertSame($first->json('data.payment.client_secret'), $second->json('data.payment.client_secret'));
        $this->assertSame(['create'], $this->gateway->operations(), 'Powtórzenie nie może tworzyć drugiej płatności.');
    }

    public function test_cart_reports_pending_booking_only_after_checkout(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->lock($this->seatIds[0]);

        $this->cart()->assertOk()->assertJsonPath('data.pending_booking', null);
        $reference = $this->checkout()->assertCreated()->json('data.booking.reference');

        $cart = $this->cart()->assertOk()
            ->assertJsonPath('data.pending_booking.reference', $reference)
            ->assertJsonPath('data.seats_count', 1);
        $this->assertGreaterThan(0, $cart->json('data.pending_booking.expires_in_seconds'));
        $this->assertSame($cart->json('data.expires_at'), $cart->json('data.pending_booking.expires_at'));
    }

    public function test_seat_added_after_checkout_blocks_checkout_and_release_all_frees_only_that_seat(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->lock($this->seatIds[0]);
        $reference = $this->checkout()->assertCreated()->json('data.booking.reference');
        $this->lock($this->seatIds[1]);

        $this->checkout()->assertStatus(409)
            ->assertJsonPath('code', 'BOOKING_ALREADY_PENDING')
            ->assertJsonPath('context.booking_reference', $reference);

        $this->withHeader('X-Session-Id', self::SESSION)
            ->deleteJson('/api/v1/screenings/'.$this->screening->id.'/seat-locks')
            ->assertOk()
            ->assertJsonPath('data.seats_count', 1)
            ->assertJsonPath('data.seats.0.seat_id', $this->seatIds[0])
            ->assertJsonPath('data.pending_booking.reference', $reference);

        $this->checkout()->assertOk()->assertJsonPath('data.booking.reference', $reference);
    }
}
