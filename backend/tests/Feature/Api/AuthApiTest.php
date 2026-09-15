<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Rejestracja, logowanie, /me i wylogowanie.
 *
 * setUp czyści cache, bo licznik rate limitera żyje właśnie tam.
 * Bez tego drugie uruchomienie pakietu w ciągu minuty zaczynałoby
 * od zużytego limitu i testy padałyby z 429 bez związku z kodem.
 * W CI (Etap 10) ustawimy CACHE_STORE=array i problem zniknie u źródła.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_rejestracja_tworzy_konto_klienta_i_zwraca_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jan Kowalski',
            'email' => 'jan@example.com',
            'password' => 'tajne1234',
            'password_confirmation' => 'tajne1234',
            'device_name' => 'telefon Jana',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'jan@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'role', 'role_label'],
                    'token',
                ],
            ]);

        $this->assertSame(
            1,
            User::where('email', 'jan@example.com')->count(),
            'Rejestracja ma utworzyć dokładnie jedno konto.'
        );
    }

    public function test_rejestracja_nie_pozwala_nadac_sobie_roli_admina(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Haker',
            'email' => 'haker@example.com',
            'password' => 'tajne1234',
            'password_confirmation' => 'tajne1234',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.user.role', 'customer');

        $this->assertSame(
            UserRole::Customer,
            User::where('email', 'haker@example.com')->sole()->role,
            'Pole role przyslane w zadaniu musi zostac zignorowane (mass assignment).'
        );
    }

    public function test_rejestracja_odrzuca_zajety_adres_email_po_polsku(): void
    {
        User::factory()->create(['email' => 'zajety@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ktos',
            'email' => 'zajety@example.com',
            'password' => 'tajne1234',
            'password_confirmation' => 'tajne1234',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.email.0', 'Konto z tym adresem e-mail już istnieje.');
    }

    public function test_haslo_bez_cyfry_jest_odrzucane_komunikatem_po_polsku(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Kuba',
            'email' => 'kuba@example.com',
            'password' => 'haslohaslo',
            'password_confirmation' => 'haslohaslo',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Hasło musi zawierać przynajmniej jedną cyfrę.');
    }

    public function test_zle_haslo_zwraca_401_bez_wskazania_przyczyny(): void
    {
        User::factory()->create([
            'email' => 'anna@example.com',
            'password' => 'poprawne123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'anna@example.com',
            'password' => 'zle-haslo',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            // Ten sam komunikat co przy nieistniejacym koncie — inaczej
            // dalo by sie zbudowac liste zarejestrowanych adresow.
            ->assertJsonPath('message', 'Nieprawidłowy adres e-mail lub hasło.');
    }

    public function test_nieistniejace_konto_zwraca_ten_sam_komunikat_co_zle_haslo(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nie-ma-takiego@example.com',
            'password' => 'cokolwiek123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Nieprawidłowy adres e-mail lub hasło.');
    }

    public function test_me_wymaga_tokenu(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_wylogowanie_uniewaznia_tylko_token_biezacego_urzadzenia(): void
    {
        $user = User::factory()->create(['password' => 'poprawne123']);

        $laptop = $user->createToken('laptop')->plainTextToken;
        $telefon = $user->createToken('telefon')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Guard Laravela cachuje rozwiazanego uzytkownika w ramach jednego
        // testu — bez wyczyszczenia kolejne zadanie dostaloby uzytkownika
        // z pamieci obiektu, a nie z tokenu, i nie sprawdzaloby niczego.
        $this->app["auth"]->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$telefon)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
