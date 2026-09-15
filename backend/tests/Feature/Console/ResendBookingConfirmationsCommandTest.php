<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\GenerateBookingTicketsPdf;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * cinema:bookings:resend-confirmations — ponawianie zgubionych potwierdzeń.
 *
 * Progi z config/tickets.php: po 15 minutach od płatności, w oknie 120 minut.
 * Testujemy granice okna i to, że rezerwacje, którym mail już wyszedł albo
 * które nie są opłacone, nie wracają do kolejki.
 */
final class ResendBookingConfirmationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_ponawia_tylko_zgubione_potwierdzenia_w_oknie_automatycznym(): void
    {
        $lost = $this->paidMinutesAgo(30);
        $tooFresh = $this->paidMinutesAgo(5);
        $tooOld = $this->paidMinutesAgo(180);
        $alreadySent = $this->paidMinutesAgo(30, confirmed: true);
        $pending = Booking::factory()->create();

        $this->artisan('cinema:bookings:resend-confirmations')
            ->expectsOutput('Ponowione potwierdzenia: 1')
            ->assertSuccessful();

        Queue::assertPushedTimes(GenerateBookingTicketsPdf::class, 1);
        Queue::assertPushed(GenerateBookingTicketsPdf::class, fn (GenerateBookingTicketsPdf $job): bool => $job->bookingId === $lost->id);

        foreach ([$tooFresh, $tooOld, $alreadySent, $pending] as $booking) {
            Queue::assertNotPushed(GenerateBookingTicketsPdf::class, fn (GenerateBookingTicketsPdf $job): bool => $job->bookingId === $booking->id);
        }
    }

    public function test_opcja_all_ponawia_takze_starsze_potwierdzenia(): void
    {
        $lost = $this->paidMinutesAgo(30);
        $old = $this->paidMinutesAgo(180);

        $this->artisan('cinema:bookings:resend-confirmations --all')
            ->expectsOutput('Ponowione potwierdzenia: 2')
            ->assertSuccessful();

        Queue::assertPushed(GenerateBookingTicketsPdf::class, fn (GenerateBookingTicketsPdf $job): bool => $job->bookingId === $lost->id);
        Queue::assertPushed(GenerateBookingTicketsPdf::class, fn (GenerateBookingTicketsPdf $job): bool => $job->bookingId === $old->id);
    }

    private function paidMinutesAgo(int $minutes, bool $confirmed = false): Booking
    {
        $paidAt = CarbonImmutable::now()->subMinutes($minutes);

        return Booking::factory()->paid()->create([
            'paid_at' => $paidAt,
            'confirmation_sent_at' => $confirmed ? $paidAt->addMinute() : null,
        ]);
    }
}
