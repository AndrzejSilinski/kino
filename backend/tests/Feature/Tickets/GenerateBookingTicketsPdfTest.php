<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\BookingStatus;
use App\Jobs\GenerateBookingTicketsPdf;
use App\Models\Booking;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\Ticket;
use App\Notifications\BookingConfirmed;
use App\Queue\RetryPolicy;
use App\Tickets\TicketPdfStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zadanie PDF, mail potwierdzający i znacznik wysłania (decyzje 74 i 75).
 *
 * Storage::fake('local') podmienia dysk na katalog tymczasowy: test nie
 * zostawia PDF-ów w prawdziwym storage/app/private/tickets.
 * Zadanie wywołujemy wprost (handle), bez kolejki — kolejkowanie po
 * płatności sprawdza BookingConfirmationFlowTest.
 */
final class GenerateBookingTicketsPdfTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hall = Hall::factory()->withSeats(1, 2)->create();
        $screening = Screening::factory()->for($hall)->create();
        $this->booking = Booking::factory()->paid()->create(['screening_id' => $screening->id, 'total_amount' => 5000]);

        foreach ($hall->seats()->orderBy('seat_number')->get() as $seat) {
            Ticket::factory()->create(['booking_id' => $this->booking->id, 'seat_id' => $seat->id, 'price' => 2500]);
        }
    }

    public function test_zadanie_zapisuje_pdf_i_zleca_potwierdzenie(): void
    {
        Notification::fake();

        $this->runJob();

        $path = app(TicketPdfStore::class)->path($this->booking);
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('local')->get($path));
        // Po zapisie atomowym nie zostaje żaden plik tymczasowy.
        $this->assertSame([$path], Storage::disk('local')->files('tickets'));

        Notification::assertSentTo(
            $this->booking->user,
            BookingConfirmed::class,
            fn (BookingConfirmed $notification): bool => $notification->bookingId === $this->booking->id,
        );
    }

    public function test_zadanie_pomija_rezerwacje_ktora_nie_jest_juz_oplacona(): void
    {
        Notification::fake();
        $this->booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->runJob();

        Storage::disk('local')->assertMissing(app(TicketPdfStore::class)->path($this->booking));
        Notification::assertNothingSent();
    }

    public function test_zadanie_nie_zleca_maila_gdy_potwierdzenie_juz_wyszlo(): void
    {
        Notification::fake();
        $this->booking->forceFill(['confirmation_sent_at' => now()])->save();

        $this->runJob();

        // PDF odświeżony (np. na potrzeby pobrania), ale drugiego maila nie ma.
        Storage::disk('local')->assertExists(app(TicketPdfStore::class)->path($this->booking));
        Notification::assertNothingSent();
    }

    public function test_mail_ma_pdf_w_zalaczniku_i_dane_seansu_bez_kodow_biletow(): void
    {
        $mail = (new BookingConfirmed($this->booking->id))->toMail($this->booking->user);
        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString($this->booking->screening->movie->title, $mail->subject);
        $this->assertStringContainsString('Rząd A, miejsce 1; Rząd A, miejsce 2', $body);
        $this->assertStringContainsString($this->booking->reference, $body);

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('bilety-'.$this->booking->reference.'.pdf', $mail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF-', $mail->rawAttachments[0]['data']);

        foreach ($this->booking->tickets()->pluck('code') as $code) {
            $this->assertStringNotContainsString((string) $code, $body.$mail->subject);
        }
    }

    public function test_wyslanie_maila_ustawia_znacznik_i_blokuje_kolejna_wysylke(): void
    {
        // notifyNow wysyła od razu, z pominięciem kolejki; MAIL_MAILER=array
        // z phpunit.xml zatrzymuje wiadomość w pamięci. Zdarzenie
        // NotificationSent uruchamia prawdziwego słuchacza znacznika.
        $this->booking->user->notifyNow(new BookingConfirmed($this->booking->id));

        $this->assertNotNull($this->booking->refresh()->confirmation_sent_at);
        $this->assertFalse((new BookingConfirmed($this->booking->id))->shouldSend($this->booking->user, 'mail'));
    }

    public function test_zadanie_i_mail_stosuja_polityke_ponowien(): void
    {
        $job = new GenerateBookingTicketsPdf($this->booking->id);
        $notification = new BookingConfirmed($this->booking->id);

        $this->assertSame(RetryPolicy::MAX_ATTEMPTS, $job->tries);
        $this->assertSame(RetryPolicy::MAX_ATTEMPTS, $notification->tries);
        $this->assertCount(RetryPolicy::MAX_ATTEMPTS - 1, $job->backoff());
        $this->assertSame((string) $this->booking->id, $job->uniqueId());
    }

    private function runJob(): void
    {
        app()->call([new GenerateBookingTicketsPdf($this->booking->id), 'handle']);
    }
}
