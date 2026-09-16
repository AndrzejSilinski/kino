<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Livewire\Admin\Bookings\BookingShow;
use App\Models\Booking;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\BookingCancelledByCinema;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentStatus;
use App\Services\BookingService;
use App\Services\SeatLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Anulowanie rezerwacji w panelu (Etap 7, blok K): tylko administrator, powód
 * wymagany, podpowiedź blokady, komunikat wyniku rozliczenia; klient w API widzi
 * stan zwrotu, ale nie widzi powodu.
 */
final class BookingCancellationPanelTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config(['payments.stripe.secret_key' => 'sk_test_atrapa']);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->createScreeningWithSeats(1, 2);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    public function test_admin_cancels_paid_booking_with_reason_and_sees_refund_result(): void
    {
        $booking = $this->paidBooking();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->get(route('admin.bookings.show', $booking))
            ->assertOk()->assertSee('Anulowanie rezerwacji')->assertSee('wire:confirm', false);

        Livewire::actingAs($admin)->test(BookingShow::class, ['booking' => $booking])
            ->set('reason', '   za krótki ')
            ->call('cancelBooking')
            ->assertHasErrors(['reason' => 'min'])
            ->set('reason', 'Seans odwołany z powodu awarii klimatyzacji.')
            ->call('cancelBooking')
            ->assertHasNoErrors()
            ->assertSet('reason', '')
            ->assertSee('Zwrot pieniędzy został zlecony')
            ->assertSee('pieniądze zwrócone')
            ->assertSee('Seans odwołany z powodu awarii klimatyzacji.')
            ->assertDontSee('Anuluj rezerwację</button>', false);

        $this->assertSame(BookingStatus::Refunded, $booking->refresh()->status);
        $this->assertSame(['refund'], $this->gateway->operations());
        Notification::assertSentTo($booking->user, BookingCancelledByCinema::class);

        // Klient w API: moment anulowania i stan zwrotu, bez treści powodu i bez autora.
        Sanctum::actingAs($booking->user);
        $this->getJson('/api/v1/bookings/'.$booking->reference)->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.cancellation.refund', 'refunded')
            ->assertJsonMissingPath('data.cancellation.reason')
            ->assertDontSee('klimatyzacji');
    }

    public function test_staff_cannot_cancel_and_does_not_see_the_form(): void
    {
        $booking = $this->paidBooking();
        $staff = User::factory()->staff($this->hall->cinema)->create();

        $this->actingAs($staff, 'web')->get(route('admin.bookings.show', $booking))
            ->assertOk()->assertDontSee('Anulowanie rezerwacji');

        Livewire::actingAs($staff)->test(BookingShow::class, ['booking' => $booking])
            ->set('reason', 'Próba anulowania przez obsługę kina.')
            ->call('cancelBooking')
            ->assertForbidden();

        $this->assertSame(BookingStatus::Paid, $booking->refresh()->status);
        $this->assertSame([], $this->gateway->operations());
    }

    public function test_form_explains_why_cancellation_is_blocked_and_service_error_is_shown(): void
    {
        $booking = $this->paidBooking();
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(BookingShow::class, ['booking' => $booking])
            ->set('reason', 'Klient zgłosił podwójny zakup biletów.');

        // Ktoś wszedł na salę między wyświetleniem strony a kliknięciem: rozstrzyga serwis.
        Ticket::query()->where('booking_id', $booking->id)->limit(1)->update(['status' => TicketStatus::Used]);
        $component->call('cancelBooking')
            ->assertHasErrors('reason')
            ->assertSee('Część biletów została już wykorzystana')
            ->assertDontSee('Anuluj rezerwację</button>', false);

        $this->assertSame(BookingStatus::Paid, $booking->refresh()->status);

        $this->travelTo($this->screening->starts_at->addMinute());
        Livewire::actingAs($admin)->test(BookingShow::class, ['booking' => $booking])
            ->assertSee('Seans już się rozpoczął');
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
}
