<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Dashboard;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Admin\SalesDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pulpit panelu (Etap 7, blok L): liczby "dziś" w strefie każdego kina, zakres
 * obsługi, obłożenie, top filmów, feed i nasłuch zdarzeń.
 *
 * ZEGAR: 2026-10-01 22:30 UTC. W Warszawie (UTC+2) to już 2 października 00:30,
 * w Lizbonie (UTC+1) jeszcze 1 października 23:30 — ta sama chwila, dwie różne "doby".
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Cinema $warsaw;

    private Cinema $lisbon;

    private PriceCategory $standard;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 22:30', 'UTC'));

        $this->standard = PriceCategory::factory()->create();
        $this->warsaw = Cinema::factory()->create(['name' => 'Kino W', 'city' => 'Warszawa', 'timezone' => 'Europe/Warsaw']);
        $this->lisbon = Cinema::factory()->create(['name' => 'Kino L', 'city' => 'Lizbona', 'timezone' => 'Europe/Lisbon']);
    }

    public function test_today_is_the_local_day_of_each_cinema_and_money_definitions_hold(): void
    {
        $dune = Movie::factory()->create(['title' => 'Diuna T']);
        $wToday = $this->screening($this->warsaw, '2026-10-02 16:00', $dune, seats: 6);
        $lToday = $this->screening($this->lisbon, '2026-10-01 22:45');

        // Warszawa: 22:10 UTC = 2.10 00:10 lokalnie -> dziś; 21:50 UTC = 1.10 23:50 -> wczoraj.
        $this->booking($wToday, 'paid', 3000, paidAt: '2026-10-01 22:10', tickets: ['valid', 'valid']);
        $this->booking($wToday, 'paid', 5000, paidAt: '2026-10-01 21:50', tickets: ['valid']);
        // Opłacona i zwrócona dziś: brutto i zwrot, bilety anulowane nie liczą się jako sprzedane.
        $this->booking($wToday, 'refunded', 4000, paidAt: '2026-10-01 22:15', tickets: ['cancelled'], refundRequestedAt: '2026-10-01 22:18', refundCompletedAt: '2026-10-01 22:20');
        // Anulowana przed pobraniem (void): pieniądze nie wpłynęły, poza brutto; zwrot w toku liczymy osobno.
        $this->booking($wToday, 'cancelled', 7000, paidAt: '2026-10-01 22:12', tickets: ['cancelled'], refundRequestedAt: '2026-10-01 22:25');
        // Void zakończony dziś: rozliczenie jest, ale zwrotu pieniędzy nie było.
        $this->booking($wToday, 'cancelled', 6000, paidAt: '2026-10-01 22:01', tickets: ['cancelled'], refundRequestedAt: '2026-10-01 22:03', refundCompletedAt: '2026-10-01 22:05');
        // Lizbona: 21:50 UTC = 1.10 22:50 lokalnie -> dziś.
        $this->booking($lToday, 'paid', 2000, paidAt: '2026-10-01 21:50', tickets: ['valid']);
        // Opłacona wczoraj, zwrot zakończony dziś (20:00 UTC = 21:00 w Lizbonie).
        $this->booking($lToday, 'refunded', 1500, paidAt: '2026-09-30 10:00', tickets: ['cancelled'], refundRequestedAt: '2026-10-01 19:00', refundCompletedAt: '2026-10-01 20:00');

        $service = app(SalesDashboardService::class);
        $admin = User::factory()->admin()->create();
        $now = CarbonImmutable::now();

        $this->assertSame(
            ['gross' => 9000, 'refunds' => 5500, 'net' => 3500, 'tickets' => 3, 'bookings' => 3, 'pending_refunds' => 1],
            $service->today($service->cinemasFor($admin), $now),
        );
        $this->assertSame(
            ['gross' => 2000, 'refunds' => 1500, 'net' => 500, 'tickets' => 1, 'bookings' => 1, 'pending_refunds' => 0],
            $service->today($service->cinemasFor($admin, $this->lisbon->id), $now),
        );

        // Obsługa Warszawy: tylko swoje kino, także z podrobionym filtrem.
        $staff = User::factory()->staff($this->warsaw)->create();
        $this->assertSame(
            ['gross' => 7000, 'refunds' => 4000, 'net' => 3000, 'tickets' => 2, 'bookings' => 2, 'pending_refunds' => 1],
            $service->today($service->cinemasFor($staff, $this->lisbon->id), $now),
        );

        Livewire::actingAs($staff)->test(Dashboard::class)
            ->set('cinemaId', (string) $this->lisbon->id)
            ->assertSeeHtml('data-metric="gross">70,00')
            ->assertDontSee('Kino L');
    }

    public function test_day_with_clock_change_has_25_hours(): void
    {
        // 25.10.2026: w Warszawie zegar cofa się z 3:00 na 2:00. Doba trwa od 24.10 22:00 UTC do 25.10 23:00 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 22:30', 'UTC'));
        $screening = $this->screening($this->warsaw, '2026-10-26 17:00');
        $this->booking($screening, 'paid', 1000, paidAt: '2026-10-25 22:50', tickets: ['valid']);   // 23:50 lokalnie, dziś
        $this->booking($screening, 'paid', 2000, paidAt: '2026-10-24 21:59', tickets: ['valid']);   // 23:59 poprzedniego dnia

        $service = app(SalesDashboardService::class);
        $today = $service->today($service->cinemasFor(User::factory()->admin()->create()), CarbonImmutable::now());

        $this->assertSame(1000, $today['gross']);
    }

    public function test_occupancy_counts_active_tickets_against_active_seats_of_screenings_starting_today(): void
    {
        $dune = Movie::factory()->create(['title' => 'Diuna T']);
        $today = $this->screening($this->warsaw, '2026-10-02 16:00', $dune, seats: 4);
        DB::table('seats')->where('hall_id', $today->hall_id)->orderByDesc('id')->limit(1)->update(['is_active' => false]);
        $this->booking($today, 'paid', 3000, paidAt: '2026-09-20 10:00', tickets: ['valid', 'used']);
        $this->booking($today, 'cancelled', 1000, paidAt: '2026-09-21 10:00', tickets: ['cancelled']);
        $this->screening($this->warsaw, '2026-10-01 20:00');                           // 1.10 22:00 lokalnie: wczoraj
        $cancelled = $this->screening($this->warsaw, '2026-10-02 19:00');
        DB::table('screenings')->where('id', $cancelled->id)->update(['status' => 'cancelled']);

        $service = app(SalesDashboardService::class);
        $rows = $service->occupancyToday($service->cinemasFor(User::factory()->staff($this->warsaw)->create()), CarbonImmutable::now());

        $this->assertCount(1, $rows);
        $this->assertSame(['time' => '18:00', 'movie' => 'Diuna T', 'sold' => 2, 'capacity' => 3, 'percent' => 66], array_intersect_key($rows[0], array_flip(['time', 'movie', 'sold', 'capacity', 'percent'])));
    }

    public function test_top_movies_use_last_seven_local_days_and_skip_cancelled_tickets(): void
    {
        $dune = Movie::factory()->create(['title' => 'Diuna T']);
        $alien = Movie::factory()->create(['title' => 'Obcy T']);
        $s1 = $this->screening($this->warsaw, '2026-10-03 16:00', $dune, seats: 5);
        $s2 = $this->screening($this->warsaw, '2026-10-03 19:00', $alien, seats: 5);

        $this->booking($s1, 'paid', 5000, paidAt: '2026-09-25 22:00', tickets: ['valid', 'valid']);   // 26.09 00:00 lokalnie: 7. doba
        $this->booking($s1, 'paid', 2500, paidAt: '2026-09-25 21:59', tickets: ['valid']);            // 25.09 23:59: poza oknem
        $this->booking($s2, 'paid', 2500, paidAt: '2026-10-01 10:00', tickets: ['valid']);
        $this->booking($s2, 'refunded', 5000, paidAt: '2026-10-01 11:00', tickets: ['cancelled', 'cancelled']);

        $service = app(SalesDashboardService::class);

        $this->assertSame(
            [['title' => 'Diuna T', 'tickets' => 2, 'revenue' => 5000], ['title' => 'Obcy T', 'tickets' => 1, 'revenue' => 2500]],
            $service->topMovies($service->cinemasFor(User::factory()->admin()->create()), CarbonImmutable::now()),
        );
    }

    public function test_feed_is_scoped_and_live_events_are_only_signals_read_back_from_the_database(): void
    {
        $own = $this->booking($this->screening($this->warsaw, '2026-10-02 16:00'), 'paid', 3000, paidAt: '2026-10-01 22:10', tickets: ['valid']);
        $foreign = $this->booking($this->screening($this->lisbon, '2026-10-02 16:00'), 'paid', 2000, paidAt: '2026-10-01 22:11', tickets: ['valid']);
        $staff = User::factory()->staff($this->warsaw)->create();

        $component = Livewire::actingAs($staff)->test(Dashboard::class)
            ->assertSee('…'.substr($own->reference, -6))
            ->assertDontSee('…'.substr($foreign->reference, -6));
        $this->assertSame(['echo-private:cinemas.'.$this->warsaw->id.'.sales,.sales.activity' => 'onSalesActivity', 'realtime-reconnected' => 'resync'], $component->instance()->getListeners());

        // Zdarzenie z cudzego kina (np. podrobione w przeglądarce) — ignorowane.
        $component->dispatch('echo-private:cinemas.'.$this->warsaw->id.'.sales,.sales.activity', ['reference' => $foreign->reference, 'status' => 'paid', 'total' => ['formatted' => '999 zł']])
            ->assertDontSee('…'.substr($foreign->reference, -6))
            ->assertSet('liveKeys', []);
        // Śmieci w polach — ignorowane.
        $component->call('onSalesActivity', ['reference' => '<script>', 'status' => 'paid'])
            ->call('onSalesActivity', ['reference' => $own->reference, 'status' => 'hacked'])
            ->assertSet('liveKeys', []);

        // Własne kino: wpis z bazy (kwota z rezerwacji, nie z przeglądarki), podświetlony.
        DB::table('bookings')->where('id', $own->id)->update(['status' => 'refunded']);
        $component->dispatch('echo-private:cinemas.'.$this->warsaw->id.'.sales,.sales.activity', ['reference' => $own->reference, 'status' => 'refunded', 'total' => ['formatted' => '999 zł']])
            ->assertSet('liveKeys', [$own->reference.':refunded'])
            ->assertSeeHtml('data-live="1"')
            ->assertSee('zwrot')
            ->assertDontSee('999 zł');
        $this->assertSame('booking.refunded', $component->get('feed')[0]['type']);

        // Po odzyskaniu połączenia feed wraca do stanu z bazy.
        $component->dispatch('realtime-reconnected')->assertSet('liveKeys', []);

        $admin = Livewire::actingAs(User::factory()->admin()->create())->test(Dashboard::class);
        $this->assertArrayHasKey('echo-private:sales,.sales.activity', $admin->instance()->getListeners());
        $admin->assertSee('…'.substr($foreign->reference, -6))->assertSee('…'.substr($own->reference, -6));
        $admin->assertDontSee($own->user->email);
    }

    public function test_realtime_scripts_are_loaded_before_livewire_only_when_reverb_is_configured(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('reverb-key')->assertDontSee('realtime.js')->assertSee('wyłączone (odświeżanie co minutę)');

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'publiczny-klucz-testu']);
        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="reverb-key" content="publiczny-klucz-testu" data-auth-endpoint="'.route('admin.broadcasting.auth').'">', $html);
        // W <head>: przed skryptem Livewire, który trafia na koniec <body> (kolejność na żywo sprawdza Playwright).
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'js/admin/realtime.js'), 'Echo musi być gotowe przed startem Livewire.');
        $this->assertLessThan(strpos($html, 'realtime.js'), strpos($html, 'echo.iife.js'));
        $this->assertStringNotContainsString('test-sekret', $html);

        // Inne strony panelu nie otwierają WebSocketu.
        $this->get(route('admin.bookings.index'))->assertOk()->assertDontSee('reverb-key');
    }

    public function test_dashboard_requires_panel_access(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(), 'web')->get(route('admin.dashboard'))->assertForbidden();
    }

    private function screening(Cinema $cinema, string $utc, ?Movie $movie = null, int $seats = 3): Screening
    {
        $start = CarbonImmutable::parse($utc, 'UTC');
        $hall = Hall::factory()->withSeats(1, $seats, $this->standard)->for($cinema)->create();

        return Screening::factory()->for($hall)->for($movie ?? Movie::factory()->create())->create([
            'starts_at' => $start, 'ends_at' => $start->addMinutes(135), 'slot_ends_at' => $start->addMinutes(155),
        ]);
    }

    /** @param list<string> $tickets statusy biletów na kolejnych wolnych miejscach sali */
    private function booking(Screening $screening, string $status, int $total, string $paidAt, array $tickets, ?string $refundRequestedAt = null, ?string $refundCompletedAt = null): Booking
    {
        $booking = Booking::factory()->create(['screening_id' => $screening->id, 'total_amount' => $total]);
        DB::table('bookings')->where('id', $booking->id)->update([
            'status' => $status,
            'paid_at' => CarbonImmutable::parse($paidAt, 'UTC'),
            'refund_requested_at' => $refundRequestedAt === null ? null : CarbonImmutable::parse($refundRequestedAt, 'UTC'),
            'refund_completed_at' => $refundCompletedAt === null ? null : CarbonImmutable::parse($refundCompletedAt, 'UTC'),
        ]);

        $taken = Ticket::query()->where('screening_id', $screening->id)->pluck('seat_id');
        $seats = DB::table('seats')->where('hall_id', $screening->hall_id)->whereNotIn('id', $taken)->orderBy('id')->limit(count($tickets))->pluck('id');

        foreach ($tickets as $i => $ticketStatus) {
            Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $seats[$i], 'price' => intdiv($total, count($tickets)), 'status' => $ticketStatus]);
        }

        return $booking->fresh();
    }
}
