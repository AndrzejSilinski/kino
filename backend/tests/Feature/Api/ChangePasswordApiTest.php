<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT /account/password (Etap 8, blok I).
 *
 * Prawdziwe tokeny (createToken + nagłówek), nie Sanctum::actingAs: sprawdzamy, które
 * tokeny przeżyją zmianę hasła, a actingAs w ogóle nie tworzy wiersza w personal_access_tokens.
 */
final class ChangePasswordApiTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'stareHaslo1';

    private const NEW = 'noweHaslo2';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create(['password' => self::OLD]);
    }

    /** @param  array<string, string>  $body */
    private function change(string $token, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->putJson('/api/v1/account/password', $body);
    }

    public function test_zmiana_hasla_wylogowuje_pozostale_urzadzenia_a_biezace_zostaje(): void
    {
        $current = $this->user->createToken('laptop')->plainTextToken;
        $phone = $this->user->createToken('telefon')->plainTextToken;
        $tablet = $this->user->createToken('tablet')->plainTextToken;

        $this->change($current, ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertOk()
            ->assertJsonPath('data.revoked_tokens', 2);

        $this->assertTrue(Hash::check(self::NEW, (string) $this->user->refresh()->password));
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
        foreach ([$phone, $tablet] as $revoked) {
            $this->app['auth']->forgetGuards();
            $this->withToken($revoked)->getJson('/api/v1/auth/me')->assertStatus(401);
        }
    }

    public function test_bledne_obecne_haslo_422_i_haslo_bez_zmian(): void
    {
        $token = $this->user->createToken('laptop')->plainTextToken;

        $this->change($token, ['current_password' => 'zgaduje123', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.current_password.0', 'Obecne hasło jest nieprawidłowe.');

        $this->assertTrue(Hash::check(self::OLD, (string) $this->user->refresh()->password));
    }

    public function test_nowe_haslo_musi_spelniac_polityke_i_roznic_sie_od_obecnego(): void
    {
        $token = $this->user->createToken('laptop')->plainTextToken;

        $this->change($token, ['current_password' => self::OLD, 'password' => 'krotkie', 'password_confirmation' => 'krotkie'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->change($token, ['current_password' => self::OLD, 'password' => self::OLD, 'password_confirmation' => self::OLD])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Nowe hasło musi się różnić od obecnego.');
    }

    public function test_limit_prob_zatrzymuje_zgadywanie_obecnego_hasla(): void
    {
        $token = $this->user->createToken('laptop')->plainTextToken;
        $guess = ['current_password' => 'zgaduje123', 'password' => self::NEW, 'password_confirmation' => self::NEW];

        for ($i = 0; $i < 5; $i++) {
            $this->change($token, $guess)->assertStatus(422);
        }

        $this->change($token, $guess)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
