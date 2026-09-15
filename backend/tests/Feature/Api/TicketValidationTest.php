<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\TicketTokenSigner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Skanowanie biletów przy wejściu na salę (decyzje 80–82).
 *
 * Seans "teraz" zaczyna się za 30 minut, więc okno wejścia (60 minut przed
 * startem) już trwa. Seans "później" jest za 6 godzin w tej samej sali —
 * na tyle daleko, że nie narusza constraintu EXCLUDE i jego okno jest
 * jeszcze zamknięte.
 */
final class TicketValidationTest extends TestCase
{
    use RefreshDatabase;

    private Hall $hall;

    private Screening $now;

    private Screening $later;

    private Ticket $ticket;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka I: liczniki limitera żyją w cache między testami.
        Cache::flush();

        $this->hall = Hall::factory()->withSeats(1, 2)->create();
        $this->now = $this->screeningStartingIn(minutes: 30);
        $this->later = $this->screeningStartingIn(minutes: 360);
        $this->ticket = $this->ticketFor($this->now);
        $this->staff = User::factory()->staff($this->hall->cinema)->create();
    }

    public function test_obsluga_wpuszcza_widza_i_oznacza_bilet_jako_wykorzystany(): void
    {
        Sanctum::actingAs($this->staff);

        $this->validate($this->ticket, $this->now)
            ->assertOk()
            ->assertJsonPath('data.status', 'used')
            ->assertJsonPath('data.seat.label', 'A1')
            ->assertJsonPath('data.screening.id', $this->now->id)
            ->assertJsonMissingPath('data.code');

        $this->ticket->refresh();
        $this->assertSame(TicketStatus::Used, $this->ticket->status);
        $this->assertSame($this->staff->id, $this->ticket->validated_by_user_id);
        $this->assertNotNull($this->ticket->validated_at);
    }

    public function test_drugi_skan_tego_samego_biletu_jest_odrzucany(): void
    {
        Sanctum::actingAs($this->staff);

        $this->validate($this->ticket, $this->now)->assertOk();

        $this->validate($this->ticket, $this->now)
            ->assertStatus(409)
            ->assertJsonPath('code', 'TICKET_ALREADY_USED')
            ->assertJsonStructure(['context' => ['validated_at']]);
    }

    public function test_bilet_na_inny_seans_jest_odrzucany_i_pozostaje_wazny(): void
    {
        Sanctum::actingAs($this->staff);

        $this->validate($this->ticket, $this->later)
            ->assertStatus(409)
            ->assertJsonPath('code', 'TICKET_WRONG_SCREENING')
            ->assertJsonPath('context.ticket_screening.id', $this->now->id);

        // Odmowa na złej sali nie może "zużyć" biletu widza.
        $this->assertSame(TicketStatus::Valid, $this->ticket->refresh()->status);
    }

    public function test_podrobiony_kod_qr_jest_odrzucany(): void
    {
        Sanctum::actingAs($this->staff);
        $token = app(TicketTokenSigner::class)->sign($this->ticket->code);
        $forged = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');

        $this->postJson('/api/v1/tickets/validate', ['token' => $forged, 'screening_id' => $this->now->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TICKET_TOKEN_INVALID');

        $this->assertSame(TicketStatus::Valid, $this->ticket->refresh()->status);
    }

    public function test_obsluga_innego_kina_nie_moze_skanowac(): void
    {
        Sanctum::actingAs(User::factory()->staff(Cinema::factory()->create())->create());

        $this->validate($this->ticket, $this->now)
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(TicketStatus::Valid, $this->ticket->refresh()->status);
    }

    public function test_klient_nie_moze_skanowac_biletow(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->validate($this->ticket, $this->now)->assertStatus(403);
    }

    public function test_administrator_moze_skanowac_w_kazdym_kinie(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->validate($this->ticket, $this->now)->assertOk()->assertJsonPath('data.status', 'used');
    }

    public function test_anulowany_bilet_jest_odrzucany(): void
    {
        Sanctum::actingAs($this->staff);
        $this->ticket->forceFill(['status' => TicketStatus::Cancelled])->save();

        $this->validate($this->ticket, $this->now)
            ->assertStatus(409)
            ->assertJsonPath('code', 'TICKET_CANCELLED');
    }

    public function test_skan_przed_otwarciem_wejscia_jest_odrzucany(): void
    {
        Sanctum::actingAs($this->staff);
        $laterTicket = $this->ticketFor($this->later);

        $this->validate($laterTicket, $this->later)
            ->assertStatus(409)
            ->assertJsonPath('code', 'TICKET_OUTSIDE_VALIDATION_WINDOW')
            ->assertJsonStructure(['context' => ['opens_at']]);
    }

    public function test_skan_wymaga_zalogowania(): void
    {
        $this->validate($this->ticket, $this->now)->assertStatus(401);
    }

    private function validate(Ticket $ticket, Screening $screening): TestResponse
    {
        return $this->postJson('/api/v1/tickets/validate', [
            'token' => app(TicketTokenSigner::class)->sign($ticket->code),
            'screening_id' => $screening->id,
        ]);
    }

    private function screeningStartingIn(int $minutes): Screening
    {
        $startsAt = CarbonImmutable::now()->addMinutes($minutes)->startOfMinute();

        return Screening::factory()->for($this->hall)->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
        ]);
    }

    private function ticketFor(Screening $screening): Ticket
    {
        $booking = Booking::factory()->paid()->create(['screening_id' => $screening->id]);
        $seat = $this->hall->seats()->orderBy('seat_number')->firstOrFail();

        return Ticket::factory()->create(['booking_id' => $booking->id, 'seat_id' => $seat->id]);
    }
}
