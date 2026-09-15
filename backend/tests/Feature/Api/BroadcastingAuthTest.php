<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ScreeningStatus;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Autoryzacja prywatnych kanałów WebSocket (Etap 6, blok D).
 *
 * BROADCASTER PODPISUJĄCY, NIE NULL: phpunit.xml ustawia
 * BROADCAST_CONNECTION=null, a NullBroadcaster niczego nie sprawdza
 * i niczego nie podpisuje. Na nim test "odmowy" byłby fałszywie zielony.
 * Dlatego w setUp() przełączamy się na połączenie reverb z TESTOWYM
 * kluczem i sekretem (niezależnym od .env). Podpis powstaje lokalnie,
 * bez sieci, więc nieosiągalny host 127.0.0.1:1 nie przeszkadza.
 *
 * ŻĄDANIA FORMULARZEM ($this->post, nie postJson) i bez Accept: JSON —
 * dokładnie tak wysyła je pusher-js. Odpowiedź i tak musi być JSON-em.
 *
 * Każdy test działa na JEDNYM kontekście uwierzytelnienia: strażnik
 * zapamiętuje użytkownika między żądaniami w tym samym teście (pułapka H).
 */
final class BroadcastingAuthTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SOCKET = '1234.5678';

    private const KEY = 'test-klucz-reverb';

    private const SECRET = 'test-sekret-reverb-0123456789abcdef';

    private User $customer;

    private Booking $booking;

    private Cinema $cinema;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka I: liczniki limitera żyją w cache między testami.
        Cache::flush();

        $this->useSigningBroadcaster();

        $this->createScreeningWithSeats(1, 2);
        $this->cinema = Cinema::query()->findOrFail($this->hall->cinema_id);
        $this->customer = User::factory()->create();
        $this->booking = Booking::factory()->create([
            'user_id' => $this->customer->id,
            'screening_id' => $this->screening->id,
        ]);
    }

    // ─── Kanał seansu: private-screenings.{id} ─────────────────────────────

    public function test_anonim_dostaje_podpis_kanalu_seansu_w_sprzedazy(): void
    {
        $channel = 'private-screenings.'.$this->screening->id;

        $this->assertSigned($this->authorizeChannel($channel), $channel);
    }

    public function test_zalogowany_klient_rowniez_dostaje_kanal_seansu(): void
    {
        Sanctum::actingAs($this->customer);
        $channel = 'private-screenings.'.$this->screening->id;

        $this->assertSigned($this->authorizeChannel($channel), $channel);
    }

    public function test_kanal_odwolanego_seansu_jest_zabroniony(): void
    {
        $this->screening->forceFill(['status' => ScreeningStatus::Cancelled])->save();

        $this->assertForbidden($this->authorizeChannel('private-screenings.'.$this->screening->id));
    }

    public function test_kanal_seansu_ktory_juz_sie_zaczal_jest_zabroniony(): void
    {
        $this->screening->forceFill(['starts_at' => now()->subMinute()])->save();

        $this->assertForbidden($this->authorizeChannel('private-screenings.'.$this->screening->id));
    }

    public function test_nieistniejacy_seans_daje_ten_sam_kod_co_brak_uprawnien(): void
    {
        $this->assertForbidden($this->authorizeChannel('private-screenings.999999999'));
    }

    // ─── Kanał rezerwacji: private-bookings.{reference} ────────────────────

    public function test_wlasciciel_dostaje_kanal_rezerwacji_przez_prawdziwy_token_bearer(): void
    {
        // Prawdziwy token, a nie Sanctum::actingAs: dowodzi, że trasa bez
        // middleware auth:sanctum i tak odczyta użytkownika z nagłówka.
        $token = $this->customer->createToken('echo')->plainTextToken;
        $channel = 'private-bookings.'.$this->booking->reference;

        $response = $this->withToken($token)->post('/api/v1/broadcasting/auth', [
            'socket_id' => self::SOCKET,
            'channel_name' => $channel,
        ]);

        $this->assertSigned($response, $channel);
    }

    public function test_inny_klient_nie_dostanie_kanalu_cudzej_rezerwacji(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertForbidden($this->authorizeChannel('private-bookings.'.$this->booking->reference));
    }

    public function test_administrator_nie_podsluchuje_kanalu_rezerwacji_klienta(): void
    {
        // Celowo węższe niż BookingPolicy::view(): admin ma feed sprzedaży.
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->assertForbidden($this->authorizeChannel('private-bookings.'.$this->booking->reference));
    }

    public function test_anonim_nie_dostanie_kanalu_rezerwacji(): void
    {
        $this->assertForbidden($this->authorizeChannel('private-bookings.'.$this->booking->reference));
    }

    public function test_nieistniejaca_rezerwacja_daje_403_a_nie_404(): void
    {
        Sanctum::actingAs($this->customer);

        $this->assertForbidden($this->authorizeChannel('private-bookings.01ZZZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    // ─── Feed sprzedaży kina: private-cinemas.{id}.sales ───────────────────

    public function test_obsluga_dostaje_feed_sprzedazy_swojego_kina(): void
    {
        Sanctum::actingAs(User::factory()->staff($this->cinema)->create());
        $channel = 'private-cinemas.'.$this->cinema->id.'.sales';

        $this->assertSigned($this->authorizeChannel($channel), $channel);
    }

    public function test_obsluga_nie_dostanie_feedu_innego_kina(): void
    {
        Sanctum::actingAs(User::factory()->staff(Cinema::factory()->create())->create());

        $this->assertForbidden($this->authorizeChannel('private-cinemas.'.$this->cinema->id.'.sales'));
    }

    public function test_administrator_dostaje_feed_sprzedazy_dowolnego_kina(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $channel = 'private-cinemas.'.$this->cinema->id.'.sales';

        $this->assertSigned($this->authorizeChannel($channel), $channel);
    }

    public function test_klient_nie_dostanie_feedu_sprzedazy_kina(): void
    {
        Sanctum::actingAs($this->customer);

        $this->assertForbidden($this->authorizeChannel('private-cinemas.'.$this->cinema->id.'.sales'));
    }

    // ─── Feed sprzedaży sieci: private-sales ───────────────────────────────

    public function test_administrator_dostaje_feed_sprzedazy_sieci(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->assertSigned($this->authorizeChannel('private-sales'), 'private-sales');
    }

    public function test_obsluga_nie_dostanie_feedu_sprzedazy_sieci(): void
    {
        Sanctum::actingAs(User::factory()->staff($this->cinema)->create());

        $this->assertForbidden($this->authorizeChannel('private-sales'));
    }

    public function test_anonim_nie_dostanie_feedu_sprzedazy_sieci(): void
    {
        $this->assertForbidden($this->authorizeChannel('private-sales'));
    }

    // ─── Kształt żądania, nieznane kanały, konfiguracja, limit ─────────────

    public function test_nieznany_kanal_prywatny_jest_zabroniony(): void
    {
        $this->assertForbidden($this->authorizeChannel('private-nieznany'));
    }

    public function test_bledny_socket_id_to_blad_walidacji(): void
    {
        $this->authorizeChannel('private-sales', 'abc')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('socket_id');
    }

    public function test_kanaly_publiczne_i_presence_nie_przechodza_walidacji(): void
    {
        foreach (['screenings.'.$this->screening->id, 'presence-screenings.'.$this->screening->id] as $channel) {
            $this->authorizeChannel($channel)
                ->assertStatus(422)
                ->assertJsonValidationErrors('channel_name');
        }
    }

    public function test_broadcaster_bez_podpisow_daje_503_ale_odmowa_zostaje_odmowa(): void
    {
        config(['broadcasting.default' => 'null']);
        app(BroadcastManager::class)->forgetDrivers();
        Log::spy();

        // Dozwolony kanał: 503 zamiast pustej odpowiedzi 200 z NullBroadcastera.
        $this->authorizeChannel('private-screenings.'.$this->screening->id)
            ->assertStatus(503)
            ->assertJsonPath('code', 'REALTIME_UNAVAILABLE');

        // Zabroniony kanał: decyzja o dostępie zapada PRZED podpisem.
        $this->assertForbidden($this->authorizeChannel('private-sales'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_limit_zadan_liczy_sie_per_sesja_zakupowa(): void
    {
        $session = str_repeat('a', 32);

        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('X-Session-Id', $session)
                ->authorizeChannel('private-nieznany')
                ->assertForbidden();
        }

        $this->withHeader('X-Session-Id', $session)
            ->authorizeChannel('private-nieznany')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // Inna sesja (inny klient za tym samym NAT-em) nie jest odcięta.
        $this->withHeader('X-Session-Id', str_repeat('b', 32))
            ->authorizeChannel('private-nieznany')
            ->assertForbidden();
    }

    // ─── Pomocnicze ────────────────────────────────────────────────────────

    private function useSigningBroadcaster(): void
    {
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

        // BroadcastManager trzyma utworzone połączenia; bez tego mógłby
        // oddać broadcaster zbudowany jeszcze na konfiguracji z phpunit.xml.
        app(BroadcastManager::class)->forgetDrivers();
    }

    private function authorizeChannel(string $channel, string $socketId = self::SOCKET): TestResponse
    {
        return $this->post('/api/v1/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ]);
    }

    /** Podpis liczony NIEZALEŻNIE od aplikacji — test nie ufa kodowi, który sprawdza. */
    private function assertSigned(TestResponse $response, string $channel): void
    {
        $expected = self::KEY.':'.hash_hmac('sha256', self::SOCKET.':'.$channel, self::SECRET);

        // assertExactJson: tylko "auth", bez "data" i bez żadnych innych pól.
        $response->assertOk()->assertExactJson(['auth' => $expected]);
    }

    private function assertForbidden(TestResponse $response): void
    {
        $response->assertForbidden()->assertJsonPath('code', 'CHANNEL_FORBIDDEN');
    }
}
