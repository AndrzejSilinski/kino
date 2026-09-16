<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RealtimeCircuitBreaker;
use App\Services\RealtimeNotifier;
use App\Services\SeatStateRecorder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Bezpiecznik wysyłki WebSocket (Etap 6, blok H).
 *
 * BROADCASTER TESTOWY zamiast Reverba: liczy próby wysyłki i na żądanie
 * rzuca wyjątek. Tylko tak widać to, o co w bezpieczniku chodzi — że przy
 * otwartym bezpieczniku próby w ogóle NIE MA. Log ani czas tego nie pokażą.
 *
 * Cache w testach to magazyn "array" (phpunit.xml), świeży w każdym teście;
 * $this->travel() przesuwa zegar, więc wygaśnięcie klucza sprawdzamy bez sleep().
 */
final class RealtimeCircuitBreakerTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** Broadcaster testowy: publiczne $attempts i $failing. */
    private object $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats(2, 5);

        $this->broadcaster = new class implements Broadcaster
        {
            public int $attempts = 0;

            public bool $failing = true;

            public function auth($request)
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->attempts++;

                if ($this->failing) {
                    throw new RuntimeException('Reverb niedostępny (atrapa).');
                }
            }
        };

        $broadcaster = $this->broadcaster;
        app(BroadcastManager::class)->extend('counting', fn () => $broadcaster);
        config([
            'broadcasting.default' => 'counting',
            'broadcasting.connections.counting' => ['driver' => 'counting'],
            'broadcasting.breaker_seconds' => 10,
        ]);
        app(BroadcastManager::class)->forgetDrivers();

        Log::spy();
    }

    // ─── Otwarcie i zamknięcie ─────────────────────────────────────────────

    public function test_awaria_otwiera_bezpiecznik_i_kolejne_wysylki_nie_probuja(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->release($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[1]], self::SESSION);

        $this->assertSame(1, $this->broadcaster->attempts, 'Po pierwszej porażce kolejne wysyłki mają być pominięte.');
        $this->assertSame(3, app(SeatStateRecorder::class)->currentVersion($this->screening->id), 'Wszystkie trzy operacje zapisane.');
        $this->assertTrue(app(RealtimeCircuitBreaker::class)->isOpen());

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['exception'] === RuntimeException::class
                && $context['paused_seconds'] === 10);
    }

    public function test_po_uplywie_czasu_wysylka_probuje_ponownie(): void
    {
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        $this->travel(11)->seconds();
        $this->broadcaster->failing = false;

        $this->assertFalse(app(RealtimeCircuitBreaker::class)->isOpen(), 'Klucz wygasł, bezpiecznik zamknięty.');

        $this->seatLocks()->lock($this->screening, [$this->seatIds[1]], self::SESSION);

        $this->assertSame(2, $this->broadcaster->attempts);
        $this->assertFalse(app(RealtimeCircuitBreaker::class)->isOpen(), 'Udana próba nie otwiera bezpiecznika.');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_udane_wysylki_nie_otwieraja_bezpiecznika(): void
    {
        $this->broadcaster->failing = false;

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->release($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[1]], self::SESSION);

        $this->assertSame(3, $this->broadcaster->attempts);
        $this->assertFalse(app(RealtimeCircuitBreaker::class)->isOpen());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_zero_sekund_wylacza_bezpiecznik(): void
    {
        config(['broadcasting.breaker_seconds' => 0]);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[1]], self::SESSION);

        $this->assertSame(2, $this->broadcaster->attempts, 'Bez bezpiecznika każda operacja próbuje wysłać.');
        Log::shouldHaveReceived('warning')->twice();
    }

    // ─── Rezerwacje i awaria cache ─────────────────────────────────────────

    public function test_otwarty_bezpiecznik_pomija_zdarzenia_rezerwacji_bez_zapytania(): void
    {
        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }

        $user = User::factory()->create();
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION, $user->id);

        DB::enableQueryLog();
        $booking = app(BookingService::class)->checkout($this->screening, self::SESSION, $user);

        $this->assertSame(1, $this->broadcaster->attempts, 'Checkout przy otwartym bezpieczniku nie wysyła nic.');
        $this->assertFalse($this->notifierQueried(), 'Przy otwartym bezpieczniku notifier nie czyta rezerwacji z bazy.');

        // Kontrola, że asercja wyżej nie jest pusta: po zamknięciu zapytanie i wysyłki są.
        $this->travel(11)->seconds();
        $this->broadcaster->failing = false;
        DB::flushQueryLog();

        $this->assertTrue(app(RealtimeNotifier::class)->bookingChanged($booking->id, BookingStatus::Expired));
        $this->assertSame(3, $this->broadcaster->attempts, 'Kanał właściciela + feed sprzedaży.');
        $this->assertTrue($this->notifierQueried());
    }

    public function test_awaria_cache_nie_blokuje_wysylki(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('has', 'add')->andThrow(new RuntimeException('Redis niedostępny (atrapa).'));
        $this->app->instance(RealtimeCircuitBreaker::class, new RealtimeCircuitBreaker($cache));

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[1]], self::SESSION);

        $this->assertSame(2, $this->broadcaster->attempts, 'Bez działającego cache notifier próbuje jak przed blokiem H.');
        Log::shouldHaveReceived('warning')->twice();
    }

    private function notifierQueried(): bool
    {
        return collect(DB::getQueryLog())->contains(fn (array $entry): bool => str_contains($entry['query'], 'seat_locks_count'));
    }
}
