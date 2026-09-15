<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PriceNotConfiguredException;
use App\Models\PriceCategory;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Services\CartPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Kalkulacja ceny koszyka.
 *
 * Klasa dziedziczy po Tests\TestCase, a nie po gołym PHPUnit\TestCase,
 * bo wycena z definicji czyta blokady i cennik z bazy — baza JEST tu
 * źródłem prawdy i zastąpienie jej atrapą testowałoby atrapę.
 * "Jednostka" oznacza tu jedną klasę w oderwaniu od HTTP, nie brak I/O.
 */
class CartPricingServiceTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESJA = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    private const INNA_SESJA = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats(rows: 2, cols: 5);
    }

    private function cart(): CartPricingService
    {
        return app(CartPricingService::class);
    }

    private function ustalCene(int $kategoriaId, int $groszy): void
    {
        ScreeningPrice::factory()->create([
            'screening_id' => $this->screening->id,
            'price_category_id' => $kategoriaId,
            'price' => $groszy,
        ]);
    }

    /** Przypisuje konkretną kategorię cenową do konkretnego miejsca. */
    private function przypiszKategorie(int $seatId, int $kategoriaId): void
    {
        Seat::query()->whereKey($seatId)->update(['price_category_id' => $kategoriaId]);
    }

    public function test_pusty_koszyk_ma_sume_zero_i_brak_timera(): void
    {
        $wynik = $this->cart()->forSession($this->screening, self::SESJA);

        $this->assertSame(0, $wynik['seats_count']);
        $this->assertSame(0, $wynik['total']['amount']);
        $this->assertNull($wynik['expires_at']);
        $this->assertNull($wynik['expires_in_seconds']);
    }

    public function test_suma_laczy_ceny_roznych_kategorii(): void
    {
        $standard = PriceCategory::factory()->create();
        $vip = PriceCategory::factory()->create();

        $this->przypiszKategorie($this->seatIds[0], $standard->id);
        $this->przypiszKategorie($this->seatIds[1], $vip->id);

        $this->ustalCene($standard->id, 2200);
        $this->ustalCene($vip->id, 4500);

        $this->seatLocks()->lock(
            $this->screening,
            [$this->seatIds[0], $this->seatIds[1]],
            self::SESJA,
        );

        $wynik = $this->cart()->forSession($this->screening, self::SESJA);

        $this->assertSame(2, $wynik['seats_count']);
        $this->assertSame(
            6700,
            $wynik['total']['amount'],
            'Suma ma byc liczba calkowita w groszach: 2200 + 4500.'
        );
        $this->assertSame("67,00\u{00A0}zł", $wynik['total']['formatted']);
    }

    public function test_koszyk_jest_wylacznie_dla_wlasnej_sesji(): void
    {
        $kategoria = PriceCategory::factory()->create();
        $this->przypiszKategorie($this->seatIds[0], $kategoria->id);
        $this->ustalCene($kategoria->id, 3000);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESJA);

        $obcy = $this->cart()->forSession($this->screening, self::INNA_SESJA);

        $this->assertSame(0, $obcy['seats_count']);
        $this->assertSame(0, $obcy['total']['amount']);
    }

    public function test_brak_ceny_dla_kategorii_konczy_sie_wyjatkiem(): void
    {
        $kategoria = PriceCategory::factory()->create();
        $this->przypiszKategorie($this->seatIds[0], $kategoria->id);
        // Celowo NIE ustalamy ceny dla tej kategorii.

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESJA);

        $this->expectException(PriceNotConfiguredException::class);

        $this->cart()->forSession($this->screening, self::SESJA);
    }

    public function test_timer_odlicza_do_najwczesniej_wygasajacej_blokady(): void
    {
        $kategoria = PriceCategory::factory()->create();
        $this->przypiszKategorie($this->seatIds[0], $kategoria->id);
        $this->ustalCene($kategoria->id, 3000);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESJA);

        $wynik = $this->cart()->forSession($this->screening, self::SESJA);
        $ttl = (int) config('cinema.seat_lock.ttl');

        $this->assertNotNull($wynik['expires_at']);
        $this->assertGreaterThan(0, $wynik['expires_in_seconds']);
        $this->assertLessThanOrEqual(
            $ttl,
            $wynik['expires_in_seconds'],
            'Timer nie moze pokazywac wiecej niz skonfigurowany TTL blokady.'
        );
    }
}
