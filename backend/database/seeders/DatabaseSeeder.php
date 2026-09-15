<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Kolejnosc ma znaczenie - kazdy kolejny seeder korzysta z danych
     * utworzonych przez poprzednie.
     */
    public function run(): void
    {
        $this->call([
            PriceCategorySeeder::class,  // kategorie musza istniec przed miejscami
            UserSeeder::class,
            MovieSeeder::class,          // filmy musza istniec przed repertuarem
            CinemaSeeder::class,         // kina -> sale -> miejsca
            StaffUserSeeder::class,      // obsluga kina: wymaga istniejacych kin
            ScreeningSeeder::class,      // repertuar + cenniki
        ]);
    }
}
