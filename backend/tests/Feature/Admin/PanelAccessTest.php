<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Dashboard;
use App\Models\Cinema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dostęp do panelu administracyjnego (Etap 7, blok B4).
 *
 * Macierz: gość / klient / obsługa / administrator × formularz logowania,
 * trasa /admin, komponent Livewire, wylogowanie i limit nieudanych prób.
 * Do tego regresja API: redirectGuestsTo nie może zamienić 401 JSON
 * w przekierowanie na formularz panelu.
 *
 * CZEGO TU NIE MA: odmowy 419 bez tokenu CSRF. PreventRequestForgery
 * w Laravelu 13 pomija weryfikację, gdy działają testy (runningUnitTests),
 * a Livewire::test wyłącza middleware. 419 sprawdza test dymny przez nginx
 * (storage/app/etap7_B3_smoke.sh).
 */
final class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_ERROR = 'Nieprawidłowy adres e-mail lub hasło.';

    private const THROTTLED_PREFIX = 'Zbyt wiele nieudanych prób logowania.';

    protected function setUp(): void
    {
        parent::setUp();

        // Pułapka I: liczniki limitera żyją w cache.
        Cache::flush();
    }

    // ─── Gość ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_from_panel_to_login_form(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_login_form_is_available_for_guest(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('Panel kina');
    }

    public function test_guest_cannot_log_out(): void
    {
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    }

    // ─── Logowanie ───────────────────────────────────────────────────────

    public function test_admin_logs_in_and_lands_on_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');

        $this->get('/admin')
            ->assertOk()
            ->assertSee('(administrator)')
            ->assertSeeLivewire(Dashboard::class);
    }

    /**
     * Session fixation: napastnik podsuwa ofierze znany sobie identyfikator sesji.
     * Wysyłamy żądanie z USTALONYM id w ciasteczku — bez regenerate() po
     * zalogowaniu sesja miałaby dalej to samo id, znane napastnikowi.
     */
    public function test_login_issues_new_session_id(): void
    {
        $admin = User::factory()->admin()->create();
        $fixedId = str_repeat('a', 40);

        $this->withCookie((string) config('session.cookie'), $fixedId)
            ->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNotSame($fixedId, session()->getId());
    }

    public function test_staff_logs_in_and_layout_shows_own_cinema(): void
    {
        $cinema = Cinema::factory()->create(['name' => 'Kino Testowe']);
        $staff = User::factory()->staff($cinema)->create();

        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->get('/admin')->assertOk()->assertSee('obsługa: Kino Testowe');
    }

    public function test_email_is_normalized_before_authentication(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'szef@cinema.test']);

        $this->post(route('admin.login.store'), ['email' => '  SZEF@Cinema.TEST ', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_validation_messages_are_in_polish(): void
    {
        $this->from(route('admin.login'))
            ->post(route('admin.login.store'), ['email' => 'to-nie-jest-email', 'password' => ''])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors([
                'email' => 'Podaj poprawny adres e-mail.',
                'password' => 'Podaj hasło.',
            ]);
    }

    // ─── Odmowy: jeden komunikat dla każdej przyczyny ───────────────────

    public function test_customer_with_valid_password_gets_generic_error_and_stays_guest(): void
    {
        $customer = User::factory()->create();

        $this->from(route('admin.login'))
            ->post(route('admin.login.store'), ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors(['email' => self::GENERIC_ERROR])
            ->assertSessionHasInput('email', $customer->email);

        // Hasło nigdy nie wraca do formularza.
        $this->assertArrayNotHasKey('password', (array) session('_old_input', []));
        $this->assertGuest('web');
    }

    public function test_wrong_password_and_unknown_account_get_identical_error(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([[$admin->email, 'zle-haslo'], ['nikt@cinema.test', 'password']] as [$email, $password]) {
            $this->from(route('admin.login'))
                ->post(route('admin.login.store'), ['email' => $email, 'password' => $password])
                ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);
        }

        $this->assertGuest('web');
    }

    // ─── Trasa /admin i komponent Livewire ───────────────────────────────

    public function test_customer_session_cannot_open_panel(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_and_staff_sessions_open_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->get('/admin')
            ->assertOk()
            ->assertSee('(administrator)');

        // Pułapka H: guard pamięta użytkownika między żądaniami w jednym teście.
        $this->app['auth']->forgetGuards();

        $this->actingAs(User::factory()->staff()->create(), 'web')
            ->get('/admin')
            ->assertOk()
            ->assertSee('obsługa:');
    }

    /**
     * Livewire::test działa BEZ middleware (RequestBroker::withoutMiddleware),
     * więc tu broni wyłącznie authorize() w mount() — i to jest sedno testu:
     * komponent nie może polegać na tym, że trasa ma can:panel.access.
     */
    public function test_dashboard_component_denies_guest_and_customer_without_route_middleware(): void
    {
        Livewire::test(Dashboard::class)->assertForbidden();

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertForbidden();
    }

    public function test_dashboard_component_renders_for_admin(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Pulpit');
    }

    public function test_logged_in_panel_user_is_sent_from_login_form_to_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_logout_ends_session_and_closes_panel_again(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('web');

        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    // ─── Limit nieudanych prób ───────────────────────────────────────────

    public function test_sixth_attempt_is_blocked_even_with_correct_password_until_decay(): void
    {
        $admin = User::factory()->admin()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'zle-haslo'])
                ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);
        }

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith(self::THROTTLED_PREFIX, (string) session('errors')->first('email'));
        $this->assertGuest('web');

        $this->travel(61)->seconds();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
    }

    /**
     * Bez clear() po udanym logowaniu stare porażki zostałyby w liczniku
     * i druga z kolejnych pięciu prób byłaby już zablokowana.
     */
    public function test_successful_login_resets_account_failure_counter(): void
    {
        $admin = User::factory()->admin()->create();

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'zle-haslo']);
        }

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->app['auth']->guard('web')->logout();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'zle-haslo'])
                ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);
        }
    }

    /** Password spraying: jedno hasło, wiele kont, jeden adres IP. */
    public function test_failures_are_limited_per_ip_across_accounts(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->post(route('admin.login.store'), ['email' => "konto{$i}@cinema.test", 'password' => 'haslo123'])
                ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);
        }

        $this->post(route('admin.login.store'), ['email' => 'konto21@cinema.test', 'password' => 'haslo123'])
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith(self::THROTTLED_PREFIX, (string) session('errors')->first('email'));
    }

    // ─── Regresja API ────────────────────────────────────────────────────

    /** Bez nagłówka Accept: application/json — dokładnie ten przypadek zmienia redirectGuestsTo. */
    public function test_api_still_answers_401_json_instead_of_redirect(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
