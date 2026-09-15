<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Cinema;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Jedno konto obsługi na każde kino: obsluga.{miasto}@cinema.test.
 *
 * Osobny seeder uruchamiany PO CinemaSeeder, bo pracownik obsługi musi mieć
 * kino (constraint users_staff_has_cinema), a UserSeeder działa, zanim kina
 * powstaną. updateOrCreate sprawia, że ponowne seedowanie nie dubluje kont.
 */
class StaffUserSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Cinema::query()->orderBy('id')->get() as $cinema) {
            User::updateOrCreate(
                ['email' => 'obsluga.'.Str::slug($cinema->city).'@cinema.test'],
                [
                    'name' => 'Obsługa - '.$cinema->name,
                    'role' => UserRole::Staff,
                    'cinema_id' => $cinema->id,
                    // Haslo wylacznie do srodowiska deweloperskiego.
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
