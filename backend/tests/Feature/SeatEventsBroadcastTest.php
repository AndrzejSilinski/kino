<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\SeatsChanged;
use App\Events\SeatsResync;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SeatStateRecorder;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\CreatesCinemaData;
use Tests\TestCase;

/**
 * Zdarzenia WebSocket o zmianie stanu miejsc (Etap 6, blok F).
 *
 * Sprawdzamy trzy gwarancje:
 *   1. KAŻDA zmiana stanu miejsc daje dokładnie jedno zdarzenie z tą samą
 *      wersją co licznik; brak zmiany = brak zdarzenia,
 *   2. zdarzenie wychodzi dopiero po COMMIT, a po ROLLBACK wcale,
 *   3. niedziałający Reverb nie psuje operacji — tylko ostrzeżenie w logu.
 *
 * Event::fake() tylko dla zdarzeń miejsc: pozostałe (np. creating modelu
 * Ticket, który nadaje kod biletu) działają normalnie.
 */
final class SeatEventsBroadcastTest extends TestCase
{
    use CreatesCinemaData;
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_SESSION = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScreeningWithSeats(2, 5);

        foreach (Seat::query()->whereIn('id', $this->seatIds)->pluck('price_category_id')->unique() as $categoryId) {
            ScreeningPrice::query()->firstOrCreate(
                ['screening_id' => $this->screening->id, 'price_category_id' => $categoryId],
                ['price' => 2500],
            );
        }
    }

    // ─── Blokady ───────────────────────────────────────────────────────────

    public function test_blokada_rozglasza_held_z_wersja_na_kanale_seansu(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[1], $this->seatIds[0]], self::SESSION);

        Event::assertDispatchedTimes(SeatsChanged::class, 1);
        Event::assertDispatched(SeatsChanged::class, function (SeatsChanged $event): bool {
            return $event->broadcastAs() === 'seats.changed'
                && $this->channelNames($event) === ['private-screenings.'.$this->screening->id]
                && $event->broadcastWith() === [
                    'screening_id' => $this->screening->id,
                    'version' => 1,
                    'seats' => ['held' => [$this->seatIds[0], $this->seatIds[1]]],
                ];
        });
    }

    public function test_retry_i_konflikt_nie_rozglaszaja_niczego(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        try {
            $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::OTHER_SESSION);
            $this->fail('Druga sesja nie miała prawa dostać zajętego miejsca.');
        } catch (SeatsUnavailableException) {
            // Oczekiwane.
        }

        Event::assertDispatchedTimes(SeatsChanged::class, 1);
    }

    // ─── Po COMMIT, nigdy po ROLLBACK ──────────────────────────────────────

    public function test_zdarzenie_wychodzi_dopiero_po_commicie_zewnetrznej_transakcji(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);

        DB::transaction(function (): void {
            $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

            // Transakcja serwisu już się zakończyła, ale zewnętrzna trwa:
            // zmiana nie jest jeszcze widoczna dla nikogo, więc i zdarzenia brak.
            Event::assertNotDispatched(SeatsChanged::class);
        });

        Event::assertDispatchedTimes(SeatsChanged::class, 1);
    }

    public function test_wycofana_transakcja_nie_rozglasza_ducha(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);

        try {
            DB::transaction(function (): void {
                $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Oczekiwane.
        }

        Event::assertNotDispatched(SeatsChanged::class);
        $this->assertSame(0, app(SeatStateRecorder::class)->currentVersion($this->screening->id),
            'Wycofane podbicie licznika też nie może zostać.');
    }

    // ─── Zwalnianie i sweep ────────────────────────────────────────────────

    public function test_zwolnienie_i_porzucenie_sesji_rozglaszaja_free(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION);
        $this->seatLocks()->release($this->screening, [$this->seatIds[0]], self::SESSION);
        $this->seatLocks()->releaseSession($this->screening, self::SESSION);

        Event::assertDispatchedTimes(SeatsChanged::class, 3);
        $this->assertSeatsBroadcast(2, ['free' => [$this->seatIds[0]]]);
        $this->assertSeatsBroadcast(3, ['free' => [$this->seatIds[1]]]);
    }

    public function test_sweep_rozglasza_osobno_dla_kazdego_seansu(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        $second = Screening::factory()->for($this->hall)->create();

        foreach ([[$this->screening, 0], [$this->screening, 1], [$second, 2]] as [$screening, $i]) {
            SeatLock::factory()->expired()->create([
                'screening_id' => $screening->id,
                'seat_id' => $this->seatIds[$i],
                'session_id' => "sesja-{$i}",
            ]);
        }

        $this->seatLocks()->sweepExpired();

        Event::assertDispatchedTimes(SeatsChanged::class, 2);
        $this->assertSeatsBroadcast(1, ['free' => [$this->seatIds[0], $this->seatIds[1]]]);
        $this->assertSeatsBroadcast(1, ['free' => [$this->seatIds[2]]], $second);
    }

    // ─── Rezerwacje i bilety ───────────────────────────────────────────────

    public function test_wystawienie_biletow_rozglasza_sold(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        $booking = $this->pendingBooking();

        app(BookingService::class)->fulfil($booking);

        $this->assertSeatsBroadcast(2, ['sold' => [$this->seatIds[0], $this->seatIds[1]]]);
    }

    public function test_wygaszenie_rezerwacji_rozglasza_free(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        $booking = $this->pendingBooking();

        app(BookingService::class)->expire($booking);

        $this->assertSeatsBroadcast(2, ['free' => [$this->seatIds[0], $this->seatIds[1]]]);
    }

    public function test_wycofanie_biletow_rozglasza_free(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        $booking = $this->pendingBooking();
        app(BookingService::class)->fulfil($booking);

        app(BookingService::class)->revoke($booking->refresh());

        Event::assertDispatchedTimes(SeatsChanged::class, 3);
        $this->assertSeatsBroadcast(3, ['free' => [$this->seatIds[0], $this->seatIds[1]]]);
    }

    // ─── Payload, rozmiar, awaria Reverba ──────────────────────────────────

    public function test_payload_nie_zawiera_danych_osobowych(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        $user = User::factory()->create();

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION, $user->id);

        Event::assertDispatched(SeatsChanged::class, function (SeatsChanged $event) use ($user): bool {
            $payload = $event->broadcastWith();
            $json = json_encode($payload, JSON_THROW_ON_ERROR);

            return array_keys($payload) === ['screening_id', 'version', 'seats']
                && ! str_contains($json, self::SESSION)
                && ! str_contains($json, $user->email)
                && ! str_contains($json, 'user');
        });
    }

    public function test_zbyt_duza_zmiana_idzie_jako_resync(): void
    {
        Event::fake([SeatsChanged::class, SeatsResync::class]);
        config(['broadcasting.max_payload_bytes' => 10]);

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION);

        Event::assertNotDispatched(SeatsChanged::class);
        Event::assertDispatched(SeatsResync::class, function (SeatsResync $event): bool {
            return $event->broadcastAs() === 'seats.resync'
                && $this->channelNames($event) === ['private-screenings.'.$this->screening->id]
                && $event->broadcastWith() === ['screening_id' => $this->screening->id, 'version' => 1];
        });
    }

    public function test_niedzialajacy_reverb_nie_psuje_blokady_miejsca(): void
    {
        // BEZ Event::fake: prawdziwy broadcaster reverb z adresem, pod którym
        // nic nie nasłuchuje (127.0.0.1:1 — połączenie odrzucone od razu).
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-klucz',
            'broadcasting.connections.reverb.secret' => 'test-sekret',
            'broadcasting.connections.reverb.app_id' => '100001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        Log::spy();

        $locks = $this->seatLocks()->lock($this->screening, [$this->seatIds[0]], self::SESSION);

        $this->assertCount(1, $locks, 'Blokada zapisana w bazie nie może przepaść przez awarię WebSocketu.');
        $this->assertSame(1, app(SeatStateRecorder::class)->currentVersion($this->screening->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['event'] === SeatsChanged::class
                && $context['exception'] === BroadcastException::class)
            ->once();
    }

    // ─── Pomocnicze ────────────────────────────────────────────────────────

    /** Dwa miejsca zablokowane (wersja 1) i rezerwacja pending. */
    private function pendingBooking(): Booking
    {
        $user = User::factory()->create();

        $this->seatLocks()->lock($this->screening, [$this->seatIds[0], $this->seatIds[1]], self::SESSION, $user->id);

        return app(BookingService::class)->checkout($this->screening, self::SESSION, $user);
    }

    /** @param array<string, list<int>> $seats */
    private function assertSeatsBroadcast(int $version, array $seats, ?Screening $screening = null): void
    {
        $screeningId = ($screening ?? $this->screening)->id;

        Event::assertDispatched(SeatsChanged::class, fn (SeatsChanged $event): bool => $event->broadcastWith() === [
            'screening_id' => $screeningId,
            'version' => $version,
            'seats' => $seats,
        ]);
    }

    /** @return list<string> */
    private function channelNames(SeatsChanged|SeatsResync $event): array
    {
        return array_map(static fn ($channel): string => (string) $channel, $event->broadcastOn());
    }
}
