<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SeatStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Wersja stanu miejsc per seans (Etap 6, blok E).
 *
 * Reguła, którą sprawdzamy: KAŻDA operacja, która zmienia stan miejsc,
 * podbija wersję DOKŁADNIE raz — niezależnie od liczby miejsc — a operacja,
 * która niczego nie zmienia (retry, 409, zwolnienie "niczego"), nie podbija.
 * Kolejność pod współbieżnością sprawdza SeatLockConcurrencyTest.
 */
final class SeatStateVersionTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_SESSION = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats(2, 5);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    // ─── Blokady ───────────────────────────────────────────────────────────

    public function test_seans_bez_zmian_ma_wersje_zero(): void
    {
        $this->assertSame(0, $this->version());
    }

    public function test_blokada_podbija_wersje_raz_na_operacje_a_nie_na_miejsce(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION);

        $this->assertSame(1, $this->version());
    }

    public function test_idempotentny_retry_blokady_nie_podbija_wersji(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        $this->assertSame(1, $this->version(), 'Retry niczego nie zmienia, więc nie może wygenerować zdarzenia.');
    }

    public function test_konflikt_409_nie_podbija_wersji(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        try {
            $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::OTHER_SESSION);
            $this->fail('Druga sesja nie miała prawa dostać zajętego miejsca.');
        } catch (SeatsUnavailableException) {
            // Oczekiwane.
        }

        $this->assertSame(1, $this->version(), 'Przegrana transakcja jest wycofana razem z ewentualnym podbiciem.');
    }

    public function test_zwolnienie_podbija_wersje_tylko_gdy_cos_zwolniono(): void
    {
        $service = $this->seatLocks();
        $service->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        $this->assertSame(0, $service->release($this->screening, [$this->seatIds[0]], self::OTHER_SESSION));
        $this->assertSame(1, $this->version(), 'Próba zwolnienia cudzej blokady niczego nie zmieniła.');

        $this->assertSame(1, $service->release($this->screening, [$this->seatIds[0]], self::SESSION));
        $this->assertSame(0, $service->release($this->screening, [$this->seatIds[0]], self::SESSION));
        $this->assertSame(2, $this->version());
    }

    public function test_porzucenie_sesji_to_jedna_wersja_dla_wszystkich_miejsc(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1], $this->seatIds[2]], self::SESSION);

        $this->assertSame(3, $this->seatLocks()->releaseSession($this->screening, self::SESSION));
        $this->assertSame(2, $this->version());
    }

    public function test_sweep_podbija_wersje_raz_na_seans_w_jednej_porcji(): void
    {
        $second = Screening::factory()->for($this->hall)->create();

        foreach ([[$this->screening, 0], [$this->screening, 1], [$second, 2]] as [$screening, $i]) {
            SeatLock::factory()->expired()->create([
                'screening_id' => $screening->id,
                'seat_id' => $this->seatIds[$i],
                'session_id' => "sesja-{$i}",
            ]);
        }

        $this->assertSame(3, $this->seatLocks()->sweepExpired());
        $this->assertSame(1, $this->version(), 'Dwa miejsca jednego seansu w jednej porcji = jedna wersja.');
        $this->assertSame(1, $this->version($second));

        $this->assertSame(0, $this->seatLocks()->sweepExpired());
        $this->assertSame(1, $this->version(), 'Pusty przebieg schedulera nie podbija wersji.');
    }

    // ─── Rezerwacje i bilety ───────────────────────────────────────────────

    public function test_wystawienie_biletow_podbija_wersje_a_checkout_nie(): void
    {
        $booking = $this->pendingBooking();

        $this->assertSame(1, $this->version(), 'Checkout nie zmienia stanu miejsc: held zostaje held.');

        app(BookingService::class)->fulfil($booking);

        $this->assertSame(2, $this->version());
    }

    public function test_wygaszenie_rezerwacji_podbija_wersje(): void
    {
        $booking = $this->pendingBooking();

        $this->assertTrue(app(BookingService::class)->expire($booking));
        $this->assertSame(2, $this->version());
    }

    public function test_wygaszenie_po_sweepie_nie_podbija_wersji_drugi_raz(): void
    {
        $booking = $this->pendingBooking();

        $this->travel((int) config('payments.window_seconds') + 1)->seconds();

        $this->assertSame(2, $this->seatLocks()->sweepExpired());
        $this->assertSame(2, $this->version());

        $this->assertTrue(app(BookingService::class)->expire($booking));
        $this->assertSame(2, $this->version(), 'Sweep już zwolnił te miejsca; wygaszenie niczego nie zmienia w planie sali.');
    }

    public function test_wycofanie_biletow_podbija_wersje(): void
    {
        $booking = $this->pendingBooking();
        app(BookingService::class)->fulfil($booking);

        $this->assertTrue(app(BookingService::class)->revoke($booking->refresh()));
        $this->assertSame(3, $this->version());
    }

    // ─── Plan sali i sam rejestr ───────────────────────────────────────────

    public function test_plan_sali_zwraca_wersje_stanu_miejsc(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        $this->withHeader('X-Session-Id', self::SESSION)
            ->getJson('/api/v1/screenings/'.$this->screening->id.'/seat-map')
            ->assertOk()
            ->assertJsonPath('data.seat_state_version', 1);
    }

    public function test_rejestr_odrzuca_nieznany_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::transaction(fn () => app(SeatStateRecorder::class)
            ->record($this->screening->id, ['zarezerwowane' => [$this->seatIds[0]]]));
    }

    public function test_pusta_zmiana_nie_tworzy_wiersza_licznika(): void
    {
        $version = DB::transaction(fn () => app(SeatStateRecorder::class)
            ->record($this->screening->id, [SeatStateRecorder::FREE => []]));

        $this->assertNull($version);
        $this->assertSame(0, DB::table('screening_seat_versions')->count());
    }

    // ─── Pomocnicze ────────────────────────────────────────────────────────

    /** Dwa miejsca zablokowane (wersja 1) i rezerwacja pending — jak w PaymentRaceTest. */
    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION, $user->id);

        return app(BookingService::class)->checkout($this->screening, self::SESSION, $user);
    }

    private function version(?Screening $screening = null): int
    {
        return app(SeatStateRecorder::class)->currentVersion(($screening ?? $this->screening)->id);
    }
}
