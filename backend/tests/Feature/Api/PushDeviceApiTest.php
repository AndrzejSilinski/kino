<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT/DELETE /account/devices (Etap 8, blok K). Prawdziwe tokeny Sanctum (createToken), bo
 * sprawdzamy powiązanie urządzenia z sesją i kaskadę przy wylogowaniu.
 */
final class PushDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private const FCM_A = 'fcmTokenA_0123456789abcdefghijklmnopqrstuvwxyz:APA91b';

    private const FCM_B = 'fcmTokenB_0123456789abcdefghijklmnopqrstuvwxyz:APA91b';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @param  array<string, mixed>  $body */
    private function register(string $bearer, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($bearer)->putJson('/api/v1/account/devices', $body);
    }

    public function test_rejestracja_jest_idempotentna_i_nie_zwraca_tokenu(): void
    {
        $bearer = User::factory()->create()->createToken('web')->plainTextToken;

        $first = $this->register($bearer, ['token' => self::FCM_A, 'platform' => 'web'])->assertCreated();
        $this->assertMatchesRegularExpression('/\A[0-9A-Z]{26}\z/', (string) $first->json('data.id'));
        $this->assertStringNotContainsString(self::FCM_A, $first->getContent());

        $this->register($bearer, ['token' => self::FCM_A, 'platform' => 'web'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, PushDevice::query()->count());
    }

    public function test_odswiezenie_tokenu_zastepuje_poprzedni(): void
    {
        $bearer = User::factory()->create()->createToken('web')->plainTextToken;
        $this->register($bearer, ['token' => self::FCM_A, 'platform' => 'web'])->assertCreated();

        $this->register($bearer, ['token' => self::FCM_B, 'platform' => 'web', 'replaces' => self::FCM_A])->assertCreated();

        $this->assertSame([self::FCM_B], PushDevice::query()->pluck('token')->all());
    }

    public function test_token_innego_konta_przechodzi_na_nowe_konto(): void
    {
        $anna = User::factory()->create();
        $piotr = User::factory()->create();
        $this->register($anna->createToken('web')->plainTextToken, ['token' => self::FCM_A, 'platform' => 'web'])->assertCreated();

        $this->register($piotr->createToken('web')->plainTextToken, ['token' => self::FCM_A, 'platform' => 'web'])->assertOk();

        $this->assertSame([$piotr->id], PushDevice::query()->pluck('user_id')->all());
    }

    public function test_wylogowanie_usuwa_urzadzenie_tej_sesji_przez_kaskade(): void
    {
        $user = User::factory()->create();
        $web = $user->createToken('web')->plainTextToken;
        $phone = $user->createToken('telefon')->plainTextToken;
        $this->register($web, ['token' => self::FCM_A, 'platform' => 'web'])->assertCreated();
        $this->register($phone, ['token' => self::FCM_B, 'platform' => 'android'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($web)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame([self::FCM_B], PushDevice::query()->pluck('token')->all());
    }

    public function test_wyrejestrowanie_wlasnego_204_a_cudzego_404(): void
    {
        $anna = User::factory()->create()->createToken('web')->plainTextToken;
        $piotr = User::factory()->create()->createToken('web')->plainTextToken;
        $id = $this->register($anna, ['token' => self::FCM_A, 'platform' => 'web'])->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->withToken($piotr)->deleteJson('/api/v1/account/devices/'.$id)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->assertSame(1, PushDevice::query()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($anna)->deleteJson('/api/v1/account/devices/'.$id)->assertNoContent();
        $this->assertSame(0, PushDevice::query()->count());
    }

    public function test_walidacja_tokenu_i_platformy(): void
    {
        $bearer = User::factory()->create()->createToken('web')->plainTextToken;

        $this->register($bearer, ['token' => 'krótki', 'platform' => 'windows'])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'Nieprawidłowy token urządzenia.')
            ->assertJsonPath('errors.platform.0', 'Platforma musi być jedną z: web, android, ios.');
    }

    public function test_limit_urzadzen_usuwa_najdawniej_widziane(): void
    {
        config(['push.max_devices_per_user' => 2]);
        $bearer = User::factory()->create()->createToken('web')->plainTextToken;

        foreach (['A', 'B', 'C'] as $i => $suffix) {
            $this->travel($i)->minutes();
            $this->register($bearer, ['token' => str_repeat($suffix, 40), 'platform' => 'web'])->assertCreated();
        }

        $this->assertEqualsCanonicalizing([str_repeat('B', 40), str_repeat('C', 40)], PushDevice::query()->pluck('token')->all());
    }
}
