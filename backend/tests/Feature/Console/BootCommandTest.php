<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cinema:boot — kroki startu kontenera wywoływane przez docker/php/entrypoint.sh (Etap 10, blok D).
 *
 * Najważniejsze są asercje o tym, czego komenda NIE robi: nie seeduje bazy z danymi,
 * nie seeduje produkcji (konta demonstracyjne mają jawne hasła) i nie ostrzega o repertuarze,
 * który jest aktualny — inaczej log startu zamieniłby się w szum, który każdy pomija.
 */
final class BootCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pusta_baza_dostaje_dane_demonstracyjne_a_kolejny_start_niczego_nie_dubluje(): void
    {
        $this->assertSame(0, User::query()->count());

        $this->artisan('cinema:boot --seed-if-empty')
            ->expectsOutputToContain('baza była pusta — wczytano dane demonstracyjne')
            ->assertSuccessful();

        $users = User::query()->count();
        $this->assertGreaterThan(0, $users);
        $this->assertTrue(Screening::query()->where('starts_at', '>', now())->exists(), 'seeder liczy repertuar od teraz');

        $this->artisan('cinema:boot --seed-if-empty')
            ->expectsOutputToContain('baza ma użytkowników — bez danych demonstracyjnych')
            ->assertSuccessful();
        $this->assertSame($users, User::query()->count());
    }

    public function test_w_produkcji_dane_demonstracyjne_nigdy_nie_trafiaja_do_bazy(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('cinema:boot --seed-if-empty')
            ->expectsOutputToContain('pominięte w produkcji')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_migracje_i_ostrzezenie_tylko_przy_zestarzalym_repertuarze(): void
    {
        $this->screening(startsInMinutes: -600);

        // "Nothing to migrate" pochodzi z samej komendy migrate: dowód, że została wywołana
        // (baza testowa jest już zmigrowana przez RefreshDatabase).
        $this->artisan('cinema:boot --migrate')
            ->expectsOutputToContain('Nothing to migrate')
            ->expectsOutputToContain('brak przyszłych seansów')
            ->assertSuccessful();

        $this->screening(startsInMinutes: 600);

        $this->artisan('cinema:boot --migrate')
            ->doesntExpectOutputToContain('brak przyszłych seansów')
            ->assertSuccessful();

        // Produkcja: rada "migrate:fresh --seed" byłaby tam groźna, więc jej nie ma.
        Screening::query()->where('starts_at', '>', now())->delete();
        $this->app['env'] = 'production';
        $this->artisan('cinema:boot --migrate')
            ->doesntExpectOutputToContain('brak przyszłych seansów')
            ->assertSuccessful();
    }

    public function test_kontrola_pliku_konta_push(): void
    {
        config(['push.enabled' => false]);
        $this->artisan('cinema:boot --check-push')
            ->expectsOutputToContain('push wyłączony')
            ->assertSuccessful();

        config(['push.enabled' => true, 'push.fcm.credentials' => '']);
        $this->artisan('cinema:boot --check-push')
            ->expectsOutputToContain('FCM_CREDENTIALS jest puste — powiadomienia push nie wyjdą')
            ->assertSuccessful();

        config(['push.fcm.credentials' => sys_get_temp_dir().'/brak-pliku-konta-'.bin2hex(random_bytes(4)).'.json']);
        $this->artisan('cinema:boot --check-push')
            ->expectsOutputToContain('pliku nie ma w tym kontenerze')
            ->assertSuccessful();

        $file = tempnam(sys_get_temp_dir(), 'konto');
        try {
            config(['push.fcm.credentials' => $file]);
            $this->artisan('cinema:boot --check-push')
                ->expectsOutputToContain('plik konta serwisowego FCM czytelny')
                ->doesntExpectOutputToContain('nie wyjdą')
                ->assertSuccessful();
        } finally {
            unlink($file);
        }
    }

    private function screening(int $startsInMinutes): Screening
    {
        $startsAt = CarbonImmutable::now()->addMinutes($startsInMinutes);

        return Screening::factory()->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(135),
            'slot_ends_at' => $startsAt->addMinutes(155),
        ]);
    }
}
