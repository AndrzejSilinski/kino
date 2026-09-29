<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Exceptions\BookingCancellationException;
use App\Models\Booking;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\User;
use App\Notifications\BookingCancelledByCinema;
use App\Services\Admin\ScreeningCancellationService;
use App\Services\BookingService;
use App\Support\ScreeningCancellationReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Odwołanie seansu RAZEM z rezerwacjami (Etap 9, blok L).
 *
 * To jedyna akcja w systemie, która jednym wywołaniem anuluje cudze zakupy i uruchamia zwroty,
 * więc testy pilnują nie tylko wyniku, ale i tego, CZEGO NIE ROBI: nie rozlicza zwrotów w pętli
 * (decyzja 344) i nie odwołuje seansu, jeśli choć jedna rezerwacja została (decyzja 346).
 */
final class ScreeningCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 08:00:00', 'UTC'));
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function screening(): Screening
    {
        $hall = Hall::factory()->withSeats(2, 3)->create();
        $startsAt = CarbonImmutable::parse('2026-10-05 16:00:00', 'UTC');

        return Screening::factory()->for($hall)->for(Movie::factory()->create())->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(100),
            'slot_ends_at' => $startsAt->addMinutes(120),
        ]);
    }

    private function service(): ScreeningCancellationService
    {
        return app(ScreeningCancellationService::class);
    }

    public function test_cancels_every_booking_notifies_clients_and_then_the_screening(): void
    {
        $screening = $this->screening();
        // Domyślny stan fabryki to rezerwacja oczekująca; opłacona dostaje intencję
        // płatności, bo bez niej nie byłoby czego zwracać.
        $paid = Booking::factory()->paid()->create([
            'screening_id' => $screening->id,
            'stripe_payment_intent_id' => 'pi_'.'testowa_intencja_jeden',
        ]);
        $pending = Booking::factory()->create(['screening_id' => $screening->id]);

        $report = $this->service()->cancelWithBookings($screening, $this->admin, 'Awaria projektora w sali A.');

        $this->assertSame(2, $report->cancelled);
        $this->assertSame([], $report->failed);
        $this->assertTrue($report->screeningCancelled);
        $this->assertSame(ScreeningStatus::Cancelled, $screening->fresh()->status);

        foreach ([$paid, $pending] as $booking) {
            $fresh = $booking->fresh();
            $this->assertSame(BookingStatus::Cancelled, $fresh->status);
            $this->assertSame('Awaria projektora w sali A.', $fresh->cancellation_reason);
            $this->assertSame($this->admin->id, $fresh->cancelled_by_user_id);
            Notification::assertSentTo($fresh->user, BookingCancelledByCinema::class);
        }
    }

    public function test_refunds_are_left_to_the_background_job_not_settled_in_the_loop(): void
    {
        // Decyzja 344: zwrot to żądanie sieciowe. Pętla po czterdziestu rezerwacjach
        // trwałaby minuty w jednym żądaniu HTTP, a awaria w połowie zostawiłaby część
        // zwrotów już wysłanych. Tu zostaje ślad, który co pięć minut przerabia
        // komenda cinema:bookings:retry-refunds.
        $screening = $this->screening();
        $paid = Booking::factory()->paid()->create([
            'screening_id' => $screening->id,
            'stripe_payment_intent_id' => 'pi_'.'testowa_intencja_dwa',
        ]);

        $report = $this->service()->cancelWithBookings($screening, $this->admin, 'Awaria projektora w sali A.');

        $fresh = $paid->fresh();
        $this->assertNotNull($fresh->refund_requested_at, 'Zwrot został zgłoszony.');
        $this->assertNull($fresh->refund_completed_at, 'Ale NIE rozliczony w pętli.');
        $this->assertSame(1, $report->refundsPending);
        $this->assertStringContainsString('zadanie w tle', $report->message());
    }

    public function test_one_failed_booking_leaves_the_screening_alive(): void
    {
        // Gdybyśmy odwołali seans mimo nieanulowanej rezerwacji, ktoś zostałby z ważnym
        // biletem na seans, którego nie ma — i dowiedziałby się pod drzwiami sali.
        $screening = $this->screening();
        Booking::factory()->paid()->create(['screening_id' => $screening->id]);
        Booking::factory()->paid()->create(['screening_id' => $screening->id]);

        $this->mock(BookingService::class, function ($mock): void {
            $mock->shouldReceive('cancelByAdmin')->twice()->andThrow(new RuntimeException('baza padła'));
        });

        $report = $this->service()->cancelWithBookings($screening, $this->admin, 'Awaria projektora w sali A.');

        $this->assertFalse($report->screeningCancelled);
        $this->assertCount(2, $report->failed);
        $this->assertSame(ScreeningStatus::Scheduled, $screening->fresh()->status);
        $this->assertStringContainsString('Nie odwołano seansu', $report->message());
    }

    public function test_reason_is_checked_once_before_anything_changes(): void
    {
        $screening = $this->screening();
        $booking = Booking::factory()->paid()->create(['screening_id' => $screening->id]);

        $this->expectException(BookingCancellationException::class);

        try {
            $this->service()->cancelWithBookings($screening, $this->admin, 'x');
        } finally {
            // Zły powód nie może zostawić połowy roboty.
            $this->assertSame(BookingStatus::Paid, $booking->fresh()->status);
            $this->assertSame(ScreeningStatus::Scheduled, $screening->fresh()->status);
            Notification::assertNothingSent();
        }
    }

    public function test_screening_without_bookings_is_simply_cancelled(): void
    {
        $screening = $this->screening();

        $report = $this->service()->cancelWithBookings($screening, $this->admin, 'Awaria projektora w sali A.');

        $this->assertInstanceOf(ScreeningCancellationReport::class, $report);
        $this->assertSame(0, $report->cancelled);
        $this->assertTrue($report->screeningCancelled);
        $this->assertStringContainsString('Nie było rezerwacji', $report->message());
        Notification::assertNothingSent();
    }
}
