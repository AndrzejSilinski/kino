<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ScreeningPrice;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Endpointy koszyka: blokowanie, zwalnianie, plan sali.
 *
 * Testujemy warstwę HTTP, nie współbieżność — tę pokrywa
 * SeatLockConcurrencyTest z Etapu 2, uruchamiający 20 procesów.
 * Tutaj sprawdzamy kontrakt: kody odpowiedzi, kształt JSON-a,
 * brak danych osobowych i poprawność wyceny.
 */
class SeatLockApiTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESJA_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const SESJA_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createScreeningWithSeats(rows: 2, cols: 5);
        $this->ustalCennik(3000);
    }

    /** Każda kategoria cenowa obecna w sali dostaje cenę dla tego seansu. */
    private function ustalCennik(int $cenaWGroszach): void
    {
        $kategorie = Seat::query()
            ->whereIn('id', $this->seatIds)
            ->pluck('price_category_id')
            ->unique();

        foreach ($kategorie as $kategoriaId) {
            ScreeningPrice::factory()->create([
                'screening_id' => $this->screening->id,
                'price_category_id' => $kategoriaId,
                'price' => $cenaWGroszach,
            ]);
        }
    }

    private function url(string $sufiks = ''): string
    {
        return '/api/v1/screenings/'.$this->screening->id.'/seat-locks'.$sufiks;
    }

    public function test_blokada_zwraca_201_z_wycena_policzona_przez_serwer(): void
    {
        $response = $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), [
                'seat_ids' => [$this->seatIds[0], $this->seatIds[1]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.seats_count', 2)
            ->assertJsonPath('data.total.amount', 6000)
            ->assertJsonPath('data.total.currency', 'PLN')
            ->assertJsonPath('meta.session_id', self::SESJA_A);

        $this->assertIsInt(
            $response->json('data.expires_in_seconds'),
            'Timer koszyka musi wrocic jako liczba sekund, nie tylko jako data.'
        );
    }

    public function test_konflikt_konczy_sie_409_z_lista_zajetych_miejsc(): void
    {
        $miejsca = [$this->seatIds[0], $this->seatIds[1]];

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => $miejsca])
            ->assertCreated();

        $this->withHeader('X-Session-Id', self::SESJA_B)
            ->postJson($this->url(), ['seat_ids' => $miejsca])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SEATS_UNAVAILABLE')
            ->assertJsonCount(2, 'context.seat_ids');
    }

    public function test_konflikt_jest_all_or_nothing(): void
    {
        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => [$this->seatIds[0]]])
            ->assertCreated();

        // Klient B prosi o miejsce zajete ORAZ o wolne. Ma nie dostac
        // zadnego — czesciowy sukces byłby dla niego nieczytelny.
        $this->withHeader('X-Session-Id', self::SESJA_B)
            ->postJson($this->url(), [
                'seat_ids' => [$this->seatIds[0], $this->seatIds[2]],
            ])
            ->assertStatus(409);

        $this->withHeader('X-Session-Id', self::SESJA_B)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.seats_count', 0);
    }

    public function test_ponowne_zadanie_nie_tworzy_drugiej_blokady(): void
    {
        $miejsca = [$this->seatIds[0]];

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => $miejsca])
            ->assertCreated();

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => $miejsca])
            ->assertCreated()
            ->assertJsonPath('data.seats_count', 1);
    }

    public function test_plan_sali_rozroznia_wlasne_blokady_od_cudzych(): void
    {
        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => [$this->seatIds[0]]])
            ->assertCreated();

        $mapa = '/api/v1/screenings/'.$this->screening->id.'/seat-map';

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->getJson($mapa)
            ->assertOk()
            ->assertJsonPath('data.summary.held_by_you', 1)
            ->assertJsonPath('data.summary.held', 0);

        $this->withHeader('X-Session-Id', self::SESJA_B)
            ->getJson($mapa)
            ->assertOk()
            ->assertJsonPath('data.summary.held_by_you', 0)
            ->assertJsonPath('data.summary.held', 1);
    }

    public function test_plan_sali_nie_ujawnia_czyja_jest_blokada(): void
    {
        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => [$this->seatIds[0]]])
            ->assertCreated();

        $odpowiedz = $this->withHeader('X-Session-Id', self::SESJA_B)
            ->getJson('/api/v1/screenings/'.$this->screening->id.'/seat-map')
            ->assertOk();

        $cudze = collect($odpowiedz->json('data.seats'))
            ->firstWhere('status', 'held');

        $this->assertNotNull($cudze, 'Miejsce zablokowane przez kogos innego ma byc widoczne.');
        $this->assertArrayNotHasKey('session_id', $cudze);
        $this->assertArrayNotHasKey('user_id', $cudze);
        $this->assertNull(
            $cudze['lock_expires_at'],
            'Czas wygasniecia cudzej blokady to wyciek informacji o cudzym koszyku.'
        );
    }

    public function test_zwolnienie_miejsca_jest_idempotentne(): void
    {
        $miejsce = $this->seatIds[0];

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => [$miejsce]])
            ->assertCreated();

        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->deleteJson($this->url('/'.$miejsce))
            ->assertOk()
            ->assertJsonPath('data.seats_count', 0);

        // Powtorka na nieistniejacej juz blokadzie: nadal 200 z koszykiem, nie 404
        // (Etap 8, blok F: 200 z koszykiem zamiast 204).
        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->deleteJson($this->url('/'.$miejsce))
            ->assertOk()
            ->assertJsonPath('data.seats_count', 0);
    }

    public function test_pusta_lista_miejsc_jest_odrzucana_po_polsku(): void
    {
        $this->withHeader('X-Session-Id', self::SESJA_A)
            ->postJson($this->url(), ['seat_ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.seat_ids.0', 'Nie wybrano żadnego miejsca.');
    }

    public function test_serwer_wydaje_sesje_gdy_klient_jej_nie_przysle(): void
    {
        $odpowiedz = $this->getJson($this->url())->assertOk();

        $sesja = $odpowiedz->headers->get('X-Session-Id');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', (string) $sesja);
        $this->assertSame($sesja, $odpowiedz->json('meta.session_id'));
    }

    public function test_smieciowy_identyfikator_sesji_konczy_sie_422(): void
    {
        $this->withHeader('X-Session-Id', 'zly-format')
            ->getJson($this->url())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_SESSION_ID');
    }
}
