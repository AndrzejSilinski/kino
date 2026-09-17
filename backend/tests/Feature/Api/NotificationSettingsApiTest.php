<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET/PATCH /account/notifications (Etap 8, blok I). */
final class NotificationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create()->fresh();
        Sanctum::actingAs($this->user);
    }

    public function test_domyslnie_bez_zgody_na_push_i_z_przypomnieniami(): void
    {
        $this->getJson('/api/v1/account/notifications')
            ->assertOk()
            ->assertExactJson(['data' => ['push_enabled' => false, 'push_consent_at' => null, 'screening_reminders' => true]]);
    }

    public function test_zgoda_na_push_zapisuje_chwile_i_nie_nadpisuje_jej_przy_ponownym_wlaczeniu(): void
    {
        CarbonImmutable::setTestNow('2026-09-17 10:00:00');
        $this->patchJson('/api/v1/account/notifications', ['push_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.push_enabled', true);
        $consent = $this->user->refresh()->push_consent_at;

        CarbonImmutable::setTestNow('2026-09-17 12:00:00');
        $this->patchJson('/api/v1/account/notifications', ['push_enabled' => true])->assertOk();

        $this->assertTrue($consent?->equalTo($this->user->refresh()->push_consent_at));
        CarbonImmutable::setTestNow();
    }

    public function test_wylaczenie_zgody_zeruje_ja_a_przypomnienia_zmieniaja_sie_niezaleznie(): void
    {
        $this->patchJson('/api/v1/account/notifications', ['push_enabled' => true])->assertOk();

        $this->patchJson('/api/v1/account/notifications', ['screening_reminders' => false])
            ->assertOk()
            ->assertJsonPath('data.push_enabled', true)
            ->assertJsonPath('data.screening_reminders', false);

        $this->patchJson('/api/v1/account/notifications', ['push_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.push_consent_at', null)
            ->assertJsonPath('data.screening_reminders', false);
    }

    public function test_pusty_patch_i_zly_typ_to_422(): void
    {
        $this->patchJson('/api/v1/account/notifications', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.push_enabled.0', 'Podaj co najmniej jedno ustawienie powiadomień.');

        $this->patchJson('/api/v1/account/notifications', ['screening_reminders' => 'może'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screening_reminders']);
    }
}
