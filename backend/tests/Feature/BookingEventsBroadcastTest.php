<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Events\SalesActivity;
use App\Events\SeatsChanged;
use App\Events\SeatsResync;
use App\Exceptions\ChannelAccessDeniedException;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\PaymentIntentStatus;
use App\Payments\WebhookEventData;
use App\Services\BookingService;
use App\Services\ChannelAuthorizationService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesCinemaData;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

/**
 * Zdarzenia rezerwacji: kanał właściciela i feed sprzedaży (Etap 6, blok G).
 *
 * Sprawdzamy cztery gwarancje:
 *   1. każde przejście rezerwacji daje dokładnie jeden wpis feedu,
 *      a przejście bez zmiany (powtórka, podwójny klik) — żadnego,
 *   2. "paid" dopiero po capture (decyzja 72), nie po samym fulfil(),
 *   3. payload bez danych osobowych, a nazwy kanałów zgadzają się
 *      z tym, co przepuszcza ChannelAuthorizationService (blok D),
 *   4. niedziałający Reverb nie psuje checkoutu.
 *
 * Queue::fake(): słuchacz QueueBookingConfirmation wstawia zadanie PDF,
 * a tu testujemy wyłącznie zdarzenia WebSocket.
 */
final class BookingEventsBroadcastTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'dddddddddddddddddddddddddddddddd';

    private const FAKED = [BookingStatusChanged::class, SalesActivity::class, SeatsChanged::class, SeatsResync::class];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['payments.stripe.secret_key' => 'sk_test_atrapa']);
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);

        $this->createScreeningWithSeats(2, 5);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    // ─── Nowa rezerwacja ───────────────────────────────────────────────────

    public function test_nowa_rezerwacja_trafia_do_feedu_z_pelnym_payloadem(): void
    {
        Event::fake(self::FAKED);
        $this->freezeTime();

        $booking = $this->pendingBooking();

        $screening = $this->screening->fresh(['movie', 'hall.cinema']);
        $cinema = $screening->hall->cinema;

        Event::assertNotDispatched(BookingStatusChanged::class);
        Event::assertDispatchedTimes(SalesActivity::class, 1);
        Event::assertDispatched(SalesActivity::class, function (SalesActivity $event) use ($booking, $screening, $cinema): bool {
            return $event->broadcastAs() === 'sales.activity'
                && $this->channelNames($event) === ['private-sales', 'private-cinemas.'.$cinema->id.'.sales']
                && $event->broadcastWith() === [
                    'type' => 'booking.created',
                    'reference' => $booking->reference,
                    'status' => 'pending',
                    'status_label' => 'Oczekuje na płatność',
                    'cinema' => ['id' => $cinema->id, 'name' => $cinema->name],
                    'screening' => [
                        'id' => $screening->id,
                        'starts_at' => $screening->starts_at->copy()->setTimezone($cinema->timezone)->toIso8601String(),
                        'movie_title' => $screening->movie->title,
                        'hall_name' => $screening->hall->name,
                    ],
                    'seats_count' => 2,
                    'total' => ['amount' => 5000, 'currency' => 'PLN', 'formatted' => "50,00\u{00A0}zł"],
                    'occurred_at' => now()->toIso8601String(),
                ];
        });
    }

    public function test_podwojny_checkout_nie_dubluje_wpisu(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();

        $again = app(BookingService::class)->checkout($this->screening, self::SESSION, $booking->user);

        $this->assertSame($booking->id, $again->id);
        Event::assertDispatchedTimes(SalesActivity::class, 1);
    }

    public function test_wycofana_transakcja_checkoutu_nie_rozglasza_niczego(): void
    {
        Event::fake(self::FAKED);
        $user = User::factory()->create();
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION, $user->id);

        try {
            DB::transaction(function () use ($user): void {
                app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Oczekiwane.
        }

        Event::assertNotDispatched(SalesActivity::class);
        $this->assertSame(0, Booking::query()->count());
    }

    // ─── Przejścia rezerwacji ──────────────────────────────────────────────

    /** @return array<string, array{string, string, string, string}> */
    public static function transitions(): array
    {
        return [
            'wygaszenie' => ['expire', 'expired', 'Wygasła', 'booking.expired'],
            'anulowanie' => ['cancel', 'cancelled', 'Anulowana', 'booking.cancelled'],
            'zwrot' => ['markRefunded', 'refunded', 'Zwrócona', 'booking.refunded'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_przejscie_rezerwacji_rozglasza_status_i_wpis_feedu(string $method, string $status, string $label, string $type): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();

        $this->assertTrue(app(BookingService::class)->{$method}($booking));

        Event::assertDispatchedTimes(BookingStatusChanged::class, 1);
        Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event) use ($booking, $status, $label): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'booking.status-changed'
                && $this->channelNames($event) === ['private-bookings.'.$booking->reference]
                && $payload['reference'] === $booking->reference
                && $payload['status'] === $status
                && $payload['status_label'] === $label;
        });
        $this->assertFeedTypes(['booking.created', $type]);
    }

    public function test_powtorne_wygaszenie_nie_rozglasza_drugi_raz(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();

        app(BookingService::class)->expire($booking);
        $this->assertFalse(app(BookingService::class)->expire($booking), 'Rezerwacja nie jest już pending.');

        Event::assertDispatchedTimes(BookingStatusChanged::class, 1);
        $this->assertFeedTypes(['booking.created', 'booking.expired']);
    }

    // ─── Płatność: dopiero po capture ──────────────────────────────────────

    public function test_wystawienie_biletow_przed_capture_nie_oglasza_sprzedazy(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();

        app(BookingService::class)->fulfil($booking);

        Event::assertNotDispatched(BookingStatusChanged::class);
        $this->assertFeedTypes(['booking.created']);
    }

    public function test_oplacenie_po_capture_rozglasza_paid_raz(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->payableBooking();

        $outcome = app(PaymentService::class)->handleEvent($this->webhook($booking, 'payment_intent.amount_capturable_updated'));

        $this->assertSame('tickets_issued', $outcome);
        Event::assertDispatchedTimes(BookingStatusChanged::class, 1);
        Event::assertDispatched(BookingStatusChanged::class, fn (BookingStatusChanged $event): bool => $event->broadcastWith()['status'] === 'paid'
            && $this->channelNames($event) === ['private-bookings.'.$booking->reference]);
        $this->assertFeedTypes(['booking.created', 'booking.paid']);
    }

    public function test_wycofanie_biletow_rozglasza_cancelled(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();
        app(BookingService::class)->fulfil($booking);

        app(BookingService::class)->revoke($booking->refresh());

        Event::assertDispatched(BookingStatusChanged::class, fn (BookingStatusChanged $event): bool => $event->broadcastWith()['status'] === 'cancelled');
        $this->assertFeedTypes(['booking.created', 'booking.cancelled']);
    }

    // ─── Dane osobowe i zgodność z autoryzacją kanałów ─────────────────────

    public function test_payload_nie_zawiera_danych_osobowych(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->payableBooking();
        app(PaymentService::class)->handleEvent($this->webhook($booking, 'payment_intent.amount_capturable_updated'));

        $owner = [];
        $feed = [];
        Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event) use (&$owner): bool {
            $owner[] = $event->broadcastWith();

            return true;
        });
        Event::assertDispatched(SalesActivity::class, function (SalesActivity $event) use (&$feed): bool {
            $feed[] = $event->broadcastWith();

            return true;
        });

        $this->assertCount(1, $owner, 'paid na kanale właściciela.');
        $this->assertCount(2, $feed, 'created + paid w feedzie.');

        // Biała lista kluczy: nowe pole w payloadzie musi być świadomą decyzją.
        $this->assertSame(['reference', 'status', 'status_label', 'occurred_at'], array_keys($owner[0]));

        foreach ($feed as $entry) {
            $this->assertSame(['type', 'reference', 'status', 'status_label', 'cinema', 'screening', 'seats_count', 'total', 'occurred_at'], array_keys($entry));
            $this->assertSame(['id', 'name'], array_keys($entry['cinema']));
            $this->assertSame(['id', 'starts_at', 'movie_title', 'hall_name'], array_keys($entry['screening']));
        }

        $json = json_encode([$owner, $feed], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($booking->user->email, $json);
        $this->assertStringNotContainsString(self::SESSION, $json);
        $this->assertStringNotContainsString((string) $booking->stripe_payment_intent_id, $json);
    }

    public function test_kanaly_zdarzen_przepuszczaja_tylko_uprawnione_role(): void
    {
        Event::fake(self::FAKED);
        $booking = $this->pendingBooking();
        app(BookingService::class)->expire($booking);

        $bookingChannels = [];
        $salesChannels = [];
        Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event) use (&$bookingChannels): bool {
            $bookingChannels = $this->channelNames($event);

            return true;
        });
        Event::assertDispatched(SalesActivity::class, function (SalesActivity $event) use (&$salesChannels): bool {
            $salesChannels = $this->channelNames($event);

            return true;
        });

        $this->useSigningBroadcaster();
        $cinema = Cinema::query()->findOrFail($this->hall->cinema_id);
        $owner = $booking->user;
        $stranger = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff($cinema)->create();
        $otherStaff = User::factory()->staff(Cinema::factory()->create())->create();

        [$bookingChannel] = $bookingChannels;
        [$allSales, $cinemaSales] = $salesChannels;

        $this->assertTrue($this->allowed($bookingChannel, $owner));
        $this->assertFalse($this->allowed($bookingChannel, $stranger));
        $this->assertFalse($this->allowed($bookingChannel, $admin), 'Kanał rezerwacji jest węższy niż podgląd: tylko właściciel.');

        $this->assertTrue($this->allowed($allSales, $admin));
        $this->assertFalse($this->allowed($allSales, $staff));
        $this->assertFalse($this->allowed($allSales, $owner));

        $this->assertTrue($this->allowed($cinemaSales, $admin));
        $this->assertTrue($this->allowed($cinemaSales, $staff));
        $this->assertFalse($this->allowed($cinemaSales, $otherStaff));
        $this->assertFalse($this->allowed($cinemaSales, $owner));
    }

    // ─── Awaria Reverba ────────────────────────────────────────────────────

    public function test_niedzialajacy_reverb_nie_psuje_checkoutu(): void
    {
        $user = User::factory()->create();
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION, $user->id);

        // BEZ Event::fake: prawdziwy broadcaster pod adresem, gdzie nic nie nasłuchuje.
        $this->useSigningBroadcaster();
        Log::spy();

        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status, 'Rezerwacja zapisana w bazie nie może przepaść przez awarię WebSocketu.');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['event'] === SalesActivity::class
                && $context['exception'] === BroadcastException::class)
            ->once();
    }

    // ─── Pomocnicze ────────────────────────────────────────────────────────

    /** Dwa miejsca zablokowane przez zalogowanego klienta i rezerwacja pending. */
    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION, $user->id);

        return app(BookingService::class)->checkout($this->screening, self::SESSION, $user);
    }

    /** Rezerwacja pending z identyfikatorem płatności — jak po startCheckout(). */
    private function payableBooking(): Booking
    {
        $booking = $this->pendingBooking();
        $booking->stripe_payment_intent_id = 'pi_events_'.$booking->id;
        $booking->save();

        return $booking;
    }

    private function webhook(Booking $booking, string $type): WebhookEventData
    {
        $amount = (int) $booking->total_amount;

        return new WebhookEventData(
            id: 'evt_'.$type.'_'.$booking->id,
            type: $type,
            createdAt: CarbonImmutable::now(),
            intent: new PaymentIntentData(
                id: (string) $booking->stripe_payment_intent_id,
                status: PaymentIntentStatus::RequiresCapture,
                amountMinor: $amount,
                amountCapturableMinor: $amount,
                amountReceivedMinor: 0,
                currency: 'PLN',
                bookingReference: $booking->reference,
            ),
        );
    }

    /** @param list<string> $expected typy wpisów feedu w kolejności wysyłki */
    private function assertFeedTypes(array $expected): void
    {
        $types = [];

        Event::assertDispatched(SalesActivity::class, function (SalesActivity $event) use (&$types): bool {
            $types[] = $event->broadcastWith()['type'];

            return true;
        });

        $this->assertSame($expected, $types);
    }

    /**
     * Broadcaster reverb z TESTOWYM kluczem: podpisuje lokalnie (autoryzacja),
     * a publikacja trafia pod 127.0.0.1:1 i kończy się od razu błędem połączenia.
     */
    private function useSigningBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-klucz',
            'broadcasting.connections.reverb.secret' => 'test-sekret',
            'broadcasting.connections.reverb.app_id' => '100001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        app(BroadcastManager::class)->forgetDrivers();
    }

    private function allowed(string $channel, User $user): bool
    {
        try {
            app(ChannelAuthorizationService::class)->authorize($channel, '1234.5678', $user);

            return true;
        } catch (ChannelAccessDeniedException) {
            return false;
        }
    }

    /** @return list<string> */
    private function channelNames(BookingStatusChanged|SalesActivity $event): array
    {
        return array_map(static fn ($channel): string => (string) $channel, $event->broadcastOn());
    }
}
