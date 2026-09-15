<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Izolacja danych między użytkownikami.
 *
 * Uwierzytelniamy przez Sanctum::actingAs, a nie przez prawdziwe
 * logowanie. Dwa powody: test nie dotyczy logowania (to pokrywa
 * AuthApiTest), a wielokrotne wywołania /auth/login zużywałyby limit
 * 5 prób na minutę i robiły testy zależnymi od zegara.
 */
class BookingAuthorizationTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private User $anna;

    private User $piotr;

    private Booking $rezerwacjaAnny;

    private Booking $rezerwacjaPiotra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats();

        $this->anna = User::factory()->create();
        $this->piotr = User::factory()->create();

        $this->rezerwacjaAnny = Booking::factory()->create([
            'user_id' => $this->anna->id,
            'screening_id' => $this->screening->id,
        ]);

        $this->rezerwacjaPiotra = Booking::factory()->create([
            'user_id' => $this->piotr->id,
            'screening_id' => $this->screening->id,
        ]);
    }

    public function test_historia_rezerwacji_wymaga_zalogowania(): void
    {
        $this->getJson('/api/v1/bookings')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_uzytkownik_widzi_na_liscie_tylko_wlasne_rezerwacje(): void
    {
        Sanctum::actingAs($this->anna);

        $odpowiedz = $this->getJson('/api/v1/bookings')->assertOk();

        $this->assertSame(
            1,
            $odpowiedz->json('meta.total'),
            'Lista ma zawierac wylacznie rezerwacje zalogowanego uzytkownika.'
        );

        $this->assertSame(
            $this->rezerwacjaAnny->reference,
            $odpowiedz->json('data.0.reference')
        );

        $this->assertStringNotContainsString(
            $this->rezerwacjaPiotra->reference,
            $odpowiedz->getContent() ?: '',
            'Referencja cudzej rezerwacji nie moze pojawic sie w odpowiedzi.'
        );
    }

    public function test_wlasciciel_otwiera_swoja_rezerwacje(): void
    {
        Sanctum::actingAs($this->anna);

        $this->getJson('/api/v1/bookings/'.$this->rezerwacjaAnny->reference)
            ->assertOk()
            ->assertJsonPath('data.reference', $this->rezerwacjaAnny->reference)
            // Wewnetrzna referencja do Stripe'a nigdy nie wychodzi do API.
            ->assertJsonMissingPath('data.stripe_payment_intent_id');
    }

    public function test_uzytkownik_nie_otworzy_cudzej_rezerwacji(): void
    {
        Sanctum::actingAs($this->anna);

        // Route model binding ZNAJDZIE rezerwacje Piotra po ULID-zie
        // i wstrzyknie ja do kontrolera. Jedynym, co dzieli Anne od
        // cudzych danych, jest BookingPolicy. To jest scenariusz IDOR.
        $this->getJson('/api/v1/bookings/'.$this->rezerwacjaPiotra->reference)
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_administrator_widzi_cudza_rezerwacje(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson('/api/v1/bookings/'.$this->rezerwacjaPiotra->reference)
            ->assertOk()
            ->assertJsonPath('data.reference', $this->rezerwacjaPiotra->reference);
    }

    public function test_nieistniejaca_rezerwacja_zwraca_resource_not_found(): void
    {
        Sanctum::actingAs($this->anna);

        $this->getJson('/api/v1/bookings/01ZZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
