<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Cinema;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /admin/broadcasting/auth — podpisy kanałów feedu dla panelu (Etap 7, blok L).
 *
 * Pułapka AL: na BROADCAST_CONNECTION=null test odmowy byłby fałszywie zielony,
 * więc przełączamy się na broadcaster podpisujący z testowym kluczem (bez sieci).
 * Żądania formularzem i bez Accept: JSON — dokładnie tak wysyła je pusher-js.
 *
 * CSRF: Laravel pomija weryfikację tokenu w testach jednostkowych, dlatego 419
 * bez tokenu sprawdza test dymny przez nginx.
 */
final class PanelBroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '1234.5678';

    private const KEY = 'test-klucz-panelu';

    private const SECRET = 'test-sekret-panelu-0123456789abcdef';

    private Cinema $own;

    private Cinema $other;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => self::KEY,
            'broadcasting.connections.reverb.secret' => self::SECRET,
            'broadcasting.connections.reverb.app_id' => '100001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        // Pułapka AM: BroadcastManager pamięta połączenie zbudowane na starej konfiguracji.
        app(BroadcastManager::class)->forgetDrivers();

        $this->own = Cinema::factory()->create();
        $this->other = Cinema::factory()->create();
    }

    public function test_admin_gets_signatures_for_network_feed_and_every_cinema_feed(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->assertSigned($this->authorizeChannel('private-sales'), 'private-sales');
        $this->assertSigned($this->authorizeChannel('private-cinemas.'.$this->other->id.'.sales'), 'private-cinemas.'.$this->other->id.'.sales');
    }

    public function test_staff_gets_only_own_cinema_feed(): void
    {
        $this->actingAs(User::factory()->staff($this->own)->create(), 'web');

        $this->assertSigned($this->authorizeChannel('private-cinemas.'.$this->own->id.'.sales'), 'private-cinemas.'.$this->own->id.'.sales');
        $this->authorizeChannel('private-cinemas.'.$this->other->id.'.sales')->assertForbidden()->assertJsonPath('code', 'CHANNEL_FORBIDDEN');
        $this->authorizeChannel('private-sales')->assertForbidden()->assertJsonPath('code', 'CHANNEL_FORBIDDEN');
        $this->authorizeChannel('private-nieznany')->assertForbidden()->assertJsonPath('code', 'CHANNEL_FORBIDDEN');
    }

    public function test_errors_are_json_even_for_form_requests_from_pusher(): void
    {
        // Gość: 401 w JSON-ie, nie przekierowanie na formularz logowania.
        $this->authorizeChannel('private-sales')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        // Klient sklepu z sesją web nie przejdzie bramki panelu.
        $this->actingAs(User::factory()->create(), 'web');
        $this->authorizeChannel('private-sales')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->authorizeChannel('private-sales', 'nie-socket')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->authorizeChannel('presence-sales')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_panel_limiter_is_per_user_and_answers_429_in_json(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');

        for ($i = 0; $i < 30; $i++) {
            $this->authorizeChannel('private-sales')->assertOk();
        }

        $this->authorizeChannel('private-sales')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // Inny użytkownik ma własną pulę.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff($this->own)->create(), 'web');
        $this->authorizeChannel('private-cinemas.'.$this->own->id.'.sales')->assertOk();
    }

    private function authorizeChannel(string $channel, string $socketId = self::SOCKET): TestResponse
    {
        return $this->post('/admin/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel]);
    }

    /** Podpis liczony niezależnie od aplikacji. */
    private function assertSigned(TestResponse $response, string $channel): void
    {
        $response->assertOk()->assertExactJson(['auth' => self::KEY.':'.hash_hmac('sha256', self::SOCKET.':'.$channel, self::SECRET)]);
    }
}
