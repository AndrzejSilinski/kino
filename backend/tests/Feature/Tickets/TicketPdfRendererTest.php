<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Ticket;
use App\Tickets\TicketPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * PDF z biletami.
 *
 * Treść sprawdzamy na HTML-u, z którego powstaje PDF — strumienie tekstu
 * w PDF są skompresowane i test musiałby je rozpakowywać. Sam PDF
 * sprawdzamy tylko pod kątem tego, czego HTML nie pokaże: nagłówka pliku
 * i liczby stron.
 *
 * Dane celowo z polskimi znakami i ze strefą czasową różną od UTC:
 * to dwie rzeczy, które w PDF-ach psują się najczęściej.
 */
final class TicketPdfRendererTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $cinema = Cinema::factory()->create([
            'name' => 'Kino Źródło',
            'city' => 'Łódź',
            'address' => 'ul. Żeromskiego 5',
            'timezone' => 'Europe/Warsaw',
        ]);
        $hall = Hall::factory()->for($cinema)->withSeats(1, 3)->create(['name' => 'Sala Złota']);
        $movie = Movie::factory()->create(['title' => 'Zażółć gęślą jaźń', 'age_rating' => '12']);

        // 17:30 UTC w styczniu to 18:30 w Warszawie (czas zimowy, UTC+1).
        $startsAt = CarbonImmutable::parse('2027-01-15 17:30:00', 'UTC');
        $screening = Screening::factory()->for($hall)->for($movie)->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
        ]);

        $this->booking = Booking::factory()->paid()->create([
            'screening_id' => $screening->id,
            'total_amount' => 5500,
        ]);

        // Celowo w odwrotnej kolejności: PDF ma sortować bilety po miejscach.
        $seats = $hall->seats()->orderBy('seat_number')->get();

        foreach ([$seats[2], $seats[0]] as $seat) {
            Ticket::factory()->create([
                'booking_id' => $this->booking->id,
                'seat_id' => $seat->id,
                'price' => 2750,
            ]);
        }
    }

    public function test_bilet_zawiera_dane_seansu_w_strefie_czasowej_kina(): void
    {
        $html = app(TicketPdfRenderer::class)->html($this->booking);

        $this->assertStringContainsString('Zażółć gęślą jaźń', $html);
        $this->assertStringContainsString('Kino Źródło', $html);
        $this->assertStringContainsString('ul. Żeromskiego 5, Łódź', $html);
        $this->assertStringContainsString('Sala Złota', $html);
        $this->assertStringContainsString('piątek, 15 stycznia 2027', $html);
        $this->assertStringContainsString('18:30', $html);
        $this->assertStringNotContainsString('17:30', $html, 'Godzina seansu nie może być w UTC.');
        // Money formatuje ze spacją nierozdzielającą (U+00A0) przed "zł".
        $this->assertStringContainsString("27,50\u{00A0}zł", $html);
        $this->assertStringContainsString('Rezerwacja '.$this->booking->reference, $html);
    }

    public function test_kazdy_bilet_ma_miejsce_i_kod_qr_a_bilety_sa_posortowane(): void
    {
        $html = app(TicketPdfRenderer::class)->html($this->booking);

        $this->assertSame(2, substr_count($html, 'alt="Kod QR biletu"'));
        $this->assertStringContainsString('Bilet 2 z 2', $html);

        $first = strpos($html, 'Rząd A, miejsce 1');
        $third = strpos($html, 'Rząd A, miejsce 3');

        $this->assertNotFalse($first);
        $this->assertNotFalse($third);
        $this->assertLessThan($third, $first, 'Bilety mają być posortowane po miejscach.');
    }

    public function test_kod_biletu_nie_wystepuje_w_dokumencie_jako_tekst(): void
    {
        $html = app(TicketPdfRenderer::class)->html($this->booking);

        // Kod biletu jest tylko w obrazie QR (i to podpisany). Jawny UUID
        // w treści dałoby się skopiować z PDF-a jednym zaznaczeniem.
        foreach ($this->booking->tickets()->pluck('code') as $code) {
            $this->assertStringNotContainsString((string) $code, $html);
        }
    }

    public function test_pdf_ma_jedna_strone_na_bilet(): void
    {
        $pdf = app(TicketPdfRenderer::class)->render($this->booking);

        $this->assertStringStartsWith('%PDF-', $pdf);
        // Słowniki stron są w PDF zapisane jawnym tekstem ("/Type /Page"),
        // w odróżnieniu od skompresowanej treści. "(?!s)" pomija "/Pages".
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf));
    }

    public function test_nieoplacona_rezerwacja_nie_dostaje_pdf(): void
    {
        $pending = Booking::factory()->create(['screening_id' => $this->booking->screening_id]);

        $this->expectException(LogicException::class);

        app(TicketPdfRenderer::class)->html($pending);
    }
}
