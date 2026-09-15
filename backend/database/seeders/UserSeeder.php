<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@cinema.test', 'role' => UserRole::Admin],
            ['name' => 'Anna Kowalska', 'email' => 'anna@cinema.test',  'role' => UserRole::Customer],
            ['name' => 'Piotr Nowak',   'email' => 'piotr@cinema.test', 'role' => UserRole::Customer],
            ['name' => 'Maria Wiśniewska', 'email' => 'maria@cinema.test', 'role' => UserRole::Customer],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    // Haslo wylacznie do srodowiska deweloperskiego.
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
