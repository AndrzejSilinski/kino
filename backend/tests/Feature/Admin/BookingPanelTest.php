<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Bookings\BookingIndex;
use App\Livewire\Admin\Bookings\BookingShow;
use App\Livewire\Admin\Screenings\ScreeningSeatPlan;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprzedaż w panelu (Etap 7, blok I): lista rezerwacji z filtrami, szczegóły,
 * plan sali seansu; zakres obsługi kina i maskowanie danych osobowych.
 */
final class BookingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Cinema $warsaw;

    private Cinema $lisbon;

    private Movie $dune;

    private PriceCategory $standard;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));

        $this->standard = PriceCategory::factory()->create(['name' => 'Standard T']);
        $this->warsaw = Cinema::factory()->create(['name' => 'Kino W', 'timezone' => 'Europe/Warsaw']);
        $this->lisbon = Cinema::factory()->create(['name' => 'Kino L', 'timezone' => 'Europe/Lisbon']);
        $this->dune = Movie::factory()->create(['title' => 'Diuna T']);
    }

    private function screeningIn(Cinema $cinema, string $utc, ?Movie $movie = null, int $cols = 3): Screening
    {
        $start = CarbonImmutable::parse($utc, 'UTC');
        $hall = Hall::factory()->withSeats(1, $cols, $this->standard)->for($cinema)->create();

        return Screening::factory()->for($hall)->for($movie ?? Movie::factory()->create())->create([
            'starts_at' => $start, 'ends_at' => $start->addMinutes(135), 'slot_ends_at' => $start->addMinutes(155),
        ]);
    }

    private function booking(Screening $screening, string $status = 'paid', ?string $email = null): Booking
    {
        $booking = Booking::factory()->create([
            'screening_id' => $screening->id,
            // users.email jest UNIQUE — domyślnie losowy adres z fabryki.
            'user_id' => User::factory()->create(['name' => 'Jan Kowalski', ...($email === null ? [] : ['email' => $email])])->id,
            'total_amount' => 2599,
        ]);
        DB::table('bookings')->where('id', $booking->id)->update(['status' => $status]);

        return $booking->fresh();
    }

    // ─── Dostęp i zakres ─────────────────────────────────────────────────

    public function test_staff_sees_only_own_cinema_even_with_forged_filter_and_emails_are_masked(): void
    {
        $own = $this->booking($this->screeningIn($this->warsaw, '2026-10-02 18:00'), email: 'anna.nowak@example.com');
        $foreign = $this->booking($this->screeningIn($this->lisbon, '2026-10-02 18:00'), email: 'obcy@example.com');
        $staff = User::factory()->staff($this->warsaw)->create();

        $this->actingAs($staff, 'web')->get(route('admin.bookings.index'))->assertOk()
            ->assertSee($own->reference)->assertDontSee($foreign->reference)
            ->assertSee('a***@example.com')->assertDontSee('anna.nowak@example.com');
        $this->get(route('admin.dashboard'))->assertSee(route('admin.bookings.index'));
        $this->get(route('admin.bookings.show', $own))->assertOk()->assertSee('a***@example.com')->assertDontSee('anna.nowak@');
        $this->get(route('admin.bookings.show', $foreign))->assertForbidden();
        $this->get(route('admin.screenings.seats', $foreign->screening))->assertForbidden();

        Livewire::actingAs($staff)->test(BookingIndex::class)
            ->set('cinemaId', (string) $this->lisbon->id)
            ->assertSee($own->reference)
            ->assertDontSee($foreign->reference);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->get(route('admin.bookings.index'))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create(), 'web')->get(route('admin.bookings.index'))
            ->assertSee($own->reference)->assertSee($foreign->reference)->assertSee('anna.nowak@example.com');
        $this->get(route('admin.bookings.show', $foreign))->assertOk();
    }

    public function test_filters_by_local_screening_day_per_cinema_movie_status_and_reference(): void
    {
        // 2.10 23:30 w Warszawie = 21:30 UTC; 2.10 23:30 w Lizbonie = 22:30 UTC.
        $warsawLate = $this->booking($this->screeningIn($this->warsaw, '2026-10-02 21:30', $this->dune));
        $lisbonLate = $this->booking($this->screeningIn($this->lisbon, '2026-10-02 22:30'), 'pending');
        // 3.10 00:30 w Warszawie = 2.10 22:30 UTC — ten sam moment co seans w Lizbonie, inny dzień lokalny.
        $warsawAfterMidnight = $this->booking($this->screeningIn($this->warsaw, '2026-10-02 22:30'), 'cancelled');

        $component = Livewire::actingAs(User::factory()->admin()->create())->test(BookingIndex::class);

        $component->set('date', '2026-10-02')
            ->assertSee($warsawLate->reference)->assertSee($lisbonLate->reference)->assertDontSee($warsawAfterMidnight->reference);
        $component->set('date', '2026-10-03')
            ->assertSee($warsawAfterMidnight->reference)->assertDontSee($lisbonLate->reference);

        $component->set('date', '')->set('movieId', (string) $this->dune->id)
            ->assertSee($warsawLate->reference)->assertDontSee($lisbonLate->reference);

        $component->set('movieId', '')->set('status', 'pending')
            ->assertSee($lisbonLate->reference)->assertDontSee($warsawLate->reference);

        $component->set('status', '')->set('cinemaId', (string) $this->lisbon->id)
            ->assertSee($lisbonLate->reference)->assertDontSee($warsawLate->reference);

        $component->set('cinemaId', '')->set('reference', strtolower(substr($warsawAfterMidnight->reference, 0, 10)))
            ->assertSee($warsawAfterMidnight->reference)->assertDontSee($warsawLate->reference);

        // Wpis ze znakami spoza alfabetu ULID (tu "!", ale też % i _ z LIKE) wyłącza filtr —
        // do zapytania LIKE trafiają wyłącznie znaki, które mogą wystąpić w numerze.
        $component->set('reference', 'ab!c')->assertSee($warsawLate->reference)->assertSee($lisbonLate->reference);
    }

    public function test_list_query_count_does_not_grow_with_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $count = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($admin)->test(BookingIndex::class)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->booking($this->screeningIn($this->warsaw, '2026-10-02 18:00'));
        $few = $count();

        for ($i = 0; $i < 12; $i++) {
            $this->booking($this->screeningIn($i % 2 ? $this->warsaw : $this->lisbon, '2026-10-03 1'.($i % 10).':00'), email: "k{$i}@example.com");
        }

        $this->assertSame($few, $count(), 'Stała liczba zapytań niezależnie od liczby rezerwacji (brak N+1).');
    }

    // ─── Szczegóły ───────────────────────────────────────────────────────

    public function test_booking_details_show_tickets_amounts_and_truncated_payment_id(): void
    {
        $screening = $this->screeningIn($this->warsaw, '2026-10-02 18:00', $this->dune);
        $booking = $this->booking($screening);
        DB::table('bookings')->where('id', $booking->id)->update(['stripe_payment_intent_id' => 'pi_3SECRETPARTabc123']);
        $seat = $screening->hall->seats()->orderBy('seat_number')->first();
        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $seat->id, 'price' => 2599]);

        Livewire::actingAs(User::factory()->admin()->create())->test(BookingShow::class, ['booking' => $booking])
            ->assertSee('opłacona')
            ->assertSee('25,99')
            ->assertSee($seat->row_label.$seat->seat_number)
            ->assertSee('ważny')
            ->assertSee('…abc123')
            ->assertDontSee('pi_3SECRETPART')
            ->assertSee(route('admin.screenings.seats', $screening));
    }

    // ─── Plan sali ───────────────────────────────────────────────────────

    public function test_seat_plan_shows_states_links_sold_seats_and_keeps_holds_anonymous(): void
    {
        $screening = $this->screeningIn($this->warsaw, '2026-10-02 18:00', cols: 4);
        ScreeningPrice::factory()->create(['screening_id' => $screening->id, 'price_category_id' => $this->standard->id, 'price' => 2500]);
        [$sold, $held, $expired, $off] = $screening->hall->seats()->orderBy('seat_number')->get()->all();
        $booking = $this->booking($screening);
        Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $sold->id]);
        SeatLock::factory()->create(['screening_id' => $screening->id, 'seat_id' => $held->id, 'session_id' => 'sesja-klienta-tajna']);
        SeatLock::factory()->expired()->create(['screening_id' => $screening->id, 'seat_id' => $expired->id]);
        $off->update(['is_active' => false]);

        $component = Livewire::actingAs(User::factory()->staff($this->warsaw)->create())
            ->test(ScreeningSeatPlan::class, ['screening' => $screening]);

        $component->assertSeeHtml('Wolne: <strong>1</strong>')
            ->assertSeeHtml('zablokowane: <strong>1</strong>')
            ->assertSeeHtml('sprzedane: <strong>1</strong>')
            ->assertSeeHtml('niedostępne: <strong>1</strong>')
            ->assertSeeHtml('href="'.route('admin.bookings.show', $booking->reference).'"')
            ->assertDontSee('sesja-klienta-tajna')
            ->assertSeeHtml('wire:poll.10s');

        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:05', 'UTC'));
        Livewire::actingAs(User::factory()->admin()->create())->test(ScreeningSeatPlan::class, ['screening' => $screening])
            ->assertDontSeeHtml('wire:poll')
            ->assertSee('widok statyczny');
    }

    public function test_components_recheck_permission_and_week_grid_links_to_seat_plan(): void
    {
        $screening = $this->screeningIn($this->warsaw, '2026-10-02 18:00');
        $booking = $this->booking($screening);
        $staff = User::factory()->staff($this->warsaw)->create();
        $otherStaff = User::factory()->staff($this->lisbon)->create();

        $this->actingAs($staff, 'web')->get(route('admin.cinemas.screenings.index', $this->warsaw))
            ->assertSee(route('admin.screenings.seats', $screening));

        $plan = Livewire::actingAs($staff)->test(ScreeningSeatPlan::class, ['screening' => $screening]);
        $this->actingAs($otherStaff);
        $plan->call('$refresh')->assertForbidden();

        $show = Livewire::actingAs($staff)->test(BookingShow::class, ['booking' => $booking]);
        $this->actingAs($otherStaff);
        $show->call('$refresh')->assertForbidden();

        $list = Livewire::actingAs($staff)->test(BookingIndex::class);
        $this->actingAs(User::factory()->create());   // klient bez dostępu do panelu
        $list->call('$refresh')->assertForbidden();
    }

    public function test_email_mask(): void
    {
        $this->assertSame('j***@wp.pl', PersonalData::maskEmail('jan.kowalski@wp.pl'));
        $this->assertSame('ż***@przykład.pl', PersonalData::maskEmail('żaneta@przykład.pl'));
        $this->assertSame('***', PersonalData::maskEmail('bez-malpy'));
        $this->assertSame('***', PersonalData::maskEmail('@wp.pl'));
        $this->assertSame('***', PersonalData::maskEmail(null));
    }
}
