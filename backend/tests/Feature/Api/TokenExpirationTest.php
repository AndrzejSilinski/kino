<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wygasanie tokenów Sanctum (Etap 8, blok D).
 *
 * SPA trzyma token w localStorage, aplikacja mobilna w pamięci telefonu. Token bez terminu
 * ważności, raz wykradziony, działałby zawsze — dlatego sanctum.expiration (domyślnie 30 dni).
 *
 * forgetGuards() między żądaniami: guard pamięta użytkownika z poprzedniego żądania w teście
 * i bez tego drugie żądanie przeszłoby bez sprawdzenia tokenu (pułapka H z Etapu 3).
 */
final class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_expiration_is_thirty_days(): void
    {
        $this->assertSame(60 * 24 * 30, config('sanctum.expiration'));
    }

    public function test_token_works_before_and_is_rejected_after_expiration(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Przeglądarka (web)')->plainTextToken;

        $this->travel(30 * 24 * 60 - 1)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(2)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_expired_tokens_are_pruned_daily_by_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--hours=24', (string) $events->first()->command);
        $this->assertSame('30 3 * * *', $events->first()->expression);
    }
}
