<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** PATCH /account/profile i nowe pole avatar_url w profilu (Etap 8, blok I). */
final class AccountProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_profil_zawiera_avatar_url_null_dla_konta_bez_avatara(): void
    {
        Sanctum::actingAs(User::factory()->create()->fresh());

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.avatar_url', null);
    }

    public function test_zmiana_imienia_przycina_spacje_i_zwraca_profil(): void
    {
        $user = User::factory()->create(['name' => 'Anna Nowak'])->fresh();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/account/profile', ['name' => '  Anna Kowalska '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Anna Kowalska');

        $this->assertSame('Anna Kowalska', $user->refresh()->name);
    }

    public function test_e_maila_i_roli_nie_da_sie_zmienic_przez_profil(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com'])->fresh();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/account/profile', ['name' => 'Anna', 'email' => 'atak@example.com', 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.email', 'anna@example.com')
            ->assertJsonPath('data.role', 'customer');

        $this->assertSame(UserRole::Customer, $user->refresh()->role);
    }

    public function test_walidacja_imienia_z_polskim_komunikatem(): void
    {
        Sanctum::actingAs(User::factory()->create()->fresh());

        $this->patchJson('/api/v1/account/profile', ['name' => 'A'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.name.0', 'Pole imię i nazwisko musi mieć co najmniej 2 znaków.');
    }

    public function test_bez_tokenu_401(): void
    {
        $this->patchJson('/api/v1/account/profile', ['name' => 'Anna'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
