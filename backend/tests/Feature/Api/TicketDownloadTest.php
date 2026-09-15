<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pobieranie biletów przez właściciela: PDF i obraz kodu QR (decyzje 78–79, 83).
 *
 * Najważniejsze scenariusze to te z wymogu 5.2 zadania: nieautoryzowany
 * użytkownik nie pobierze cudzego biletu, a kod biletu nie wycieka w JSON-ie.
 */
final class TicketDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $piotr;

    private Booking $paidBooking;

    private Booking $piotrBooking;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka I: liczniki limitera żyją w cache między testami.
        Cache::flush();
        Storage::fake('local');

        $hall = Hall::factory()->withSeats(1, 3)->create();
        $screening = Screening::factory()->for($hall)->create();
        $seats = $hall->seats()->orderBy('seat_number')->get();

        $this->anna = User::factory()->create();
        $this->piotr = User::factory()->create();

        $this->paidBooking = Booking::factory()->paid()->create(['user_id' => $this->anna->id, 'screening_id' => $screening->id]);
        $this->piotrBooking = Booking::factory()->paid()->create(['user_id' => $this->piotr->id, 'screening_id' => $screening->id]);

        Ticket::factory()->create(['booking_id' => $this->paidBooking->id, 'seat_id' => $seats[0]->id]);
        Ticket::factory()->create(['booking_id' => $this->paidBooking->id, 'seat_id' => $seats[1]->id]);
        Ticket::factory()->create(['booking_id' => $this->piotrBooking->id, 'seat_id' => $seats[2]->id]);
    }

    public function test_wlasciciel_pobiera_pdf_z_biletami(): void
    {
        Sanctum::actingAs($this->anna);

        $response = $this->get($this->pdfUrl($this->paidBooking))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=bilety-'.$this->paidBooking->reference.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_uzytkownik_nie_pobierze_cudzego_pdf(): void
    {
        Sanctum::actingAs($this->anna);

        $this->getJson($this->pdfUrl($this->piotrBooking))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_pobranie_wymaga_zalogowania(): void
    {
        $this->getJson($this->pdfUrl($this->paidBooking))
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_nieoplacona_rezerwacja_nie_ma_biletow_do_pobrania(): void
    {
        Sanctum::actingAs($this->anna);
        $pending = Booking::factory()->create(['user_id' => $this->anna->id, 'screening_id' => $this->paidBooking->screening_id]);

        $this->getJson($this->pdfUrl($pending))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKING_TICKETS_UNAVAILABLE')
            ->assertJsonPath('context.booking_status', 'pending');
    }

    public function test_wlasciciel_dostaje_obraz_qr_swojego_biletu(): void
    {
        Sanctum::actingAs($this->anna);
        $ticket = $this->paidBooking->tickets()->firstOrFail();

        $response = $this->get($this->qrUrl($this->paidBooking, $ticket))->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", (string) $response->getContent());
    }

    public function test_bilet_z_innej_rezerwacji_pod_wlasna_rezerwacja_daje_404(): void
    {
        Sanctum::actingAs($this->anna);
        $piotrTicket = $this->piotrBooking->tickets()->firstOrFail();

        // Własna rezerwacja w adresie, cudzy bilet: scopeBindings nie znajdzie
        // go wśród biletów tej rezerwacji, więc policy nawet nie jest pytana.
        $this->getJson($this->qrUrl($this->paidBooking, $piotrTicket))
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_szczegoly_rezerwacji_maja_adres_qr_zamiast_kodu_biletu(): void
    {
        Sanctum::actingAs($this->anna);

        $response = $this->getJson('/api/v1/bookings/'.$this->paidBooking->reference)
            ->assertOk()
            ->assertJsonMissingPath('data.tickets.0.code');

        // Porównujemy same ścieżki: route() buduje pełny adres z APP_URL.
        $expected = $this->paidBooking->tickets()->get()
            ->map(fn (Ticket $ticket): string => $this->qrUrl($this->paidBooking, $ticket))
            ->sort()->values()->all();
        $actual = collect($response->json('data.tickets'))
            ->map(fn (array $ticket): string => (string) parse_url((string) $ticket['qr_url'], PHP_URL_PATH))
            ->sort()->values()->all();

        $this->assertSame($expected, $actual);

        foreach ($this->paidBooking->tickets()->pluck('code') as $code) {
            $this->assertStringNotContainsString((string) $code, (string) $response->getContent());
        }
    }

    private function pdfUrl(Booking $booking): string
    {
        return '/api/v1/bookings/'.$booking->reference.'/tickets/pdf';
    }

    private function qrUrl(Booking $booking, Ticket $ticket): string
    {
        return '/api/v1/bookings/'.$booking->reference.'/tickets/'.$ticket->id.'/qr';
    }
}
