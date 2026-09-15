<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Cinema;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spójność roli obsługi z kinem pilnowana przez BAZĘ (users_staff_has_cinema).
 *
 * Test omija PHP celowo: zapis idzie przez fabrykę, a nie przez serwis.
 * Sprawdzamy ostatnią linię obrony — to, co zostanie, gdy kod aplikacji
 * (panel admina, seeder, ręczny SQL) się pomyli.
 *
 * Każdy nieudany zapis ma osobny test: w PostgreSQL błąd przerywa bieżącą
 * transakcję testu i kolejne zapytanie w tym samym teście by nie przeszło.
 */
final class StaffCinemaConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_obsluga_z_kinem_jest_poprawna(): void
    {
        $cinema = Cinema::factory()->create();
        $staff = User::factory()->staff($cinema)->create();

        $this->assertSame(UserRole::Staff, $staff->refresh()->role);
        $this->assertSame($cinema->id, $staff->cinema_id);
        $this->assertTrue($staff->isStaff());
    }

    public function test_obsluga_bez_kina_jest_odrzucana_przez_baze(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_staff_has_cinema');

        User::factory()->create(['role' => UserRole::Staff, 'cinema_id' => null]);
    }

    public function test_klient_z_kinem_jest_odrzucany_przez_baze(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_staff_has_cinema');

        User::factory()->create(['role' => UserRole::Customer, 'cinema_id' => Cinema::factory()->create()->id]);
    }
}
