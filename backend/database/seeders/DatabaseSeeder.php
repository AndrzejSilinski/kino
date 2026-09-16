<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\CatalogCache;
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

        // Etap 7: po migrate:fresh --seed identyfikatory kin i seansów zaczynają
        // się od nowa. Nowa epoka unieważnia cały cache katalogu naraz — stare
        // klucze z tymi samymi id wskazywałyby dane, których już nie ma.
        app(CatalogCache::class)->bumpEpoch();
    }
}
