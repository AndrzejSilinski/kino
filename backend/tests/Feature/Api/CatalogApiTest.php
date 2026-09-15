<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Cinema;
use App\Models\PriceCategory;
use App\Models\ScreeningPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Publiczna ścieżka zakupowa: kina, kalendarz, repertuar, seans.
 *
 * Wszystkie te endpointy działają bez tokenu — klient przegląda
 * repertuar zanim w ogóle pomyśli o założeniu konta.
 */
class CatalogApiTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private Cinema $kino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats(rows: 2, cols: 5);

        $this->kino = $this->hall->cinema;
        $this->kino->update(['is_active' => true]);
    }

    /** Data seansu w strefie czasowej kina — tak, jak widzi ją klient. */
    private function dataSeansu(): string
    {
        return $this->screening->starts_at
            ->copy()
            ->setTimezone($this->kino->timezone)
            ->toDateString();
    }

    private function urlRepertuaru(string $query = ''): string
    {
        return '/api/v1/cinemas/'.$this->kino->getRouteKey().'/screenings'.$query;
    }

    public function test_lista_kin_jest_pogrupowana_po_miastach(): void
    {
        $odpowiedz = $this->getJson('/api/v1/cinemas')->assertOk();

        $grupy = collect($odpowiedz->json('data'));

        $this->assertGreaterThan(0, $grupy->count());
        $this->assertArrayHasKey('city', $grupy->first());
        $this->assertArrayHasKey('cinemas', $grupy->first());

        $miasta = $grupy->pluck('city')->all();
        $this->assertSame(
            $miasta,
            collect($miasta)->unique()->values()->all(),
            'Kazde miasto moze wystapic na liscie tylko raz.'
        );
    }

    public function test_nieczynne_kino_nie_pojawia_sie_na_liscie(): void
    {
        $zamkniete = Cinema::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/cinemas')
            ->assertOk()
            ->assertJsonMissing(['slug' => $zamkniete->slug]);
    }

    public function test_nieczynne_kino_zwraca_404_resource_not_found(): void
    {
        $zamkniete = Cinema::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/cinemas/'.$zamkniete->getRouteKey())
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_kalendarz_zwraca_dzien_z_dostepnym_seansem(): void
    {
        $odpowiedz = $this->getJson(
            '/api/v1/cinemas/'.$this->kino->getRouteKey().'/screening-dates'
        )->assertOk();

        $this->assertContains(
            $this->dataSeansu(),
            collect($odpowiedz->json('data'))->pluck('date')->all(),
            'Dzien z zaplanowanym seansem musi byc w kalendarzu.'
        );

        $this->assertSame($this->kino->timezone, $odpowiedz->json('meta.timezone'));
    }

    public function test_repertuar_dnia_zawiera_flagi_dostepnosci_i_paginacje(): void
    {
        $odpowiedz = $this->getJson($this->urlRepertuaru('?date='.$this->dataSeansu()))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'starts_at', 'projection_type', 'projection_type_label',
                    'hall', 'movie', 'seats', 'is_sold_out', 'has_started', 'is_bookable']],
                'links',
                'meta' => ['current_page', 'total'],
            ]);

        $this->assertSame($this->dataSeansu(), $odpowiedz->json('meta.date'));
    }

    public function test_zla_data_konczy_sie_422_po_polsku(): void
    {
        $this->getJson($this->urlRepertuaru('?date=11-09-2026'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath(
                'errors.date.0',
                'Data musi być w formacie RRRR-MM-DD, na przykład 2026-09-11.'
            );
    }

    public function test_szczegoly_seansu_zawieraja_cennik_i_kino(): void
    {
        $kategoria = PriceCategory::factory()->create();
        ScreeningPrice::factory()->create([
            'screening_id' => $this->screening->id,
            'price_category_id' => $kategoria->id,
            'price' => 2800,
        ]);

        $this->getJson('/api/v1/screenings/'.$this->screening->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->screening->id)
            ->assertJsonPath('data.hall.cinema.slug', $this->kino->slug)
            ->assertJsonPath('data.prices.0.price.amount', 2800);
    }
}
