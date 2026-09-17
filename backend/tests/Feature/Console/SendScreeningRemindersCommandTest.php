<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Models\Booking;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\Ticket;
use App\Notifications\ScreeningReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * cinema:screenings:send-reminders — przypomnienia 2 godziny przed seansem.
 *
 * Każda rezerwacja dostaje własną salę i własny seans, więc dowolne godziny
 * nie naruszają constraintu EXCLUDE. Okno z config/tickets.php: 120 minut.
 */
final class SendScreeningRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_przypomina_tylko_oplacone_rezerwacje_seansow_w_oknie(): void
    {
        $due = $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 180);
        $paidLate = $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 100);
        $tooFar = $this->booking(startsInMinutes: 300, paidMinutesBeforeStart: 400);
        $started = $this->booking(startsInMinutes: -10, paidMinutesBeforeStart: 300);
        $cancelledScreening = $this->booking(90, 180, screeningStatus: ScreeningStatus::Cancelled);
        $pending = $this->booking(90, 180, bookingStatus: BookingStatus::Pending);
        $alreadyReminded = $this->booking(90, 180, reminded: true);

        $this->artisan('cinema:screenings:send-reminders')
            ->expectsOutput('Wysłane przypomnienia: 1')
            ->assertSuccessful();

        Notification::assertSentTimes(ScreeningReminder::class, 1);
        Notification::assertSentTo(
            $due->user()->firstOrFail(),
            ScreeningReminder::class,
            fn (ScreeningReminder $notification): bool => $notification->bookingId === $due->id,
        );

        $this->assertNotNull($due->refresh()->reminder_sent_at);

        foreach ([$paidLate, $tooFar, $started, $cancelledScreening, $pending] as $booking) {
            $this->assertNull($booking->refresh()->reminder_sent_at, 'Rezerwacja '.$booking->reference.' nie powinna dostać przypomnienia.');
        }
    }

    public function test_klient_z_wylaczonymi_przypomnieniami_ich_nie_dostaje_a_rezerwacja_nie_jest_zajeta(): void
    {
        $optedOut = $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 180);
        $optedOut->user()->update(['screening_reminders' => false]);

        $this->artisan('cinema:screenings:send-reminders')
            ->expectsOutput('Wysłane przypomnienia: 0')
            ->assertSuccessful();

        Notification::assertNothingSent();
        // Niezajęta: jeśli klient włączy przypomnienia przed seansem, dostanie je przy kolejnym przebiegu.
        $this->assertNull($optedOut->refresh()->reminder_sent_at);

        $optedOut->user()->update(['screening_reminders' => true]);
        $this->artisan('cinema:screenings:send-reminders')->expectsOutput('Wysłane przypomnienia: 1')->assertSuccessful();
    }

    public function test_drugie_uruchomienie_nie_wysyla_ponownie(): void
    {
        $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 180);

        $this->artisan('cinema:screenings:send-reminders')->assertSuccessful();

        $this->artisan('cinema:screenings:send-reminders')
            ->expectsOutput('Wysłane przypomnienia: 0')
            ->assertSuccessful();

        Notification::assertSentTimes(ScreeningReminder::class, 1);
    }

    public function test_tresc_przypomnienia_ma_godzine_w_strefie_kina_i_miejsca_bez_kodu_biletu(): void
    {
        $booking = $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 180);
        $localTime = $booking->screening()->firstOrFail()->starts_at->copy()->setTimezone('Europe/Warsaw')->format('H:i');

        $mail = (new ScreeningReminder($booking->id))->toMail($booking->user()->firstOrFail());
        $body = implode("\n", $mail->introLines);

        $this->assertStringStartsWith('Przypomnienie: ', $mail->subject);
        $this->assertStringEndsWith($localTime, $mail->subject);
        $this->assertStringContainsString('Rząd A, miejsce 1', $body);
        $this->assertSame([], $mail->rawAttachments, 'Przypomnienie nie niesie PDF-a (decyzja 90).');
        $this->assertStringNotContainsString((string) $booking->tickets()->value('code'), $body.$mail->subject);
    }

    public function test_przypomnienie_nie_wychodzi_gdy_rezerwacje_anulowano_przed_wysylka(): void
    {
        $booking = $this->booking(startsInMinutes: 90, paidMinutesBeforeStart: 180);
        $notification = new ScreeningReminder($booking->id);

        $this->assertTrue($notification->shouldSend($booking->user()->firstOrFail(), 'mail'));

        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->assertFalse($notification->shouldSend($booking->user()->firstOrFail(), 'mail'));
    }

    private function booking(
        int $startsInMinutes,
        int $paidMinutesBeforeStart,
        ScreeningStatus $screeningStatus = ScreeningStatus::Scheduled,
        BookingStatus $bookingStatus = BookingStatus::Paid,
        bool $reminded = false,
    ): Booking {
        $startsAt = CarbonImmutable::now()->addMinutes($startsInMinutes)->startOfMinute();
        $hall = Hall::factory()->withSeats(1, 1)->create();

        $screening = Screening::factory()->for($hall)->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
            'status' => $screeningStatus,
        ]);

        $booking = Booking::factory()->create([
            'screening_id' => $screening->id,
            'status' => $bookingStatus,
            'paid_at' => $bookingStatus === BookingStatus::Paid ? $startsAt->subMinutes($paidMinutesBeforeStart) : null,
            'reminder_sent_at' => $reminded ? CarbonImmutable::now()->subMinutes(5) : null,
        ]);

        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $hall->seats()->firstOrFail()->id]);

        return $booking;
    }
}
