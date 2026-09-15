<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Movie;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MovieSeeder extends Seeder
{
    public function run(): void
    {
        $movies = [
            ['title' => 'Diuna: Część druga', 'original_title' => 'Dune: Part Two', 'duration_minutes' => 166, 'age_rating' => '12', 'genres' => ['Sci-Fi', 'Przygodowy']],
            ['title' => 'Oppenheimer',        'original_title' => 'Oppenheimer',    'duration_minutes' => 180, 'age_rating' => '15', 'genres' => ['Dramat', 'Historyczny']],
            ['title' => 'Incepcja',           'original_title' => 'Inception',      'duration_minutes' => 148, 'age_rating' => '12', 'genres' => ['Sci-Fi', 'Thriller']],
            ['title' => 'Interstellar',       'original_title' => 'Interstellar',   'duration_minutes' => 169, 'age_rating' => '12', 'genres' => ['Sci-Fi', 'Dramat']],
            ['title' => 'Parasite',           'original_title' => 'Gisaengchung',   'duration_minutes' => 132, 'age_rating' => '16', 'genres' => ['Dramat', 'Thriller']],
            ['title' => 'Barbie',             'original_title' => 'Barbie',         'duration_minutes' => 114, 'age_rating' => '12', 'genres' => ['Komedia', 'Przygodowy']],
            ['title' => 'Toy Story 4',        'original_title' => 'Toy Story 4',    'duration_minutes' => 100, 'age_rating' => 'B/O', 'genres' => ['Animacja', 'Familijny']],
            ['title' => 'Wszystko wszędzie naraz', 'original_title' => 'Everything Everywhere All at Once', 'duration_minutes' => 139, 'age_rating' => '16', 'genres' => ['Sci-Fi', 'Komedia']],
        ];

        foreach ($movies as $movie) {
            Movie::updateOrCreate(
                ['slug' => Str::slug($movie['title'])],
                [
                    ...$movie,
                    'slug' => Str::slug($movie['title']),
                    'description' => 'Opis filmu '.$movie['title'].'. Treść wypełniona przez seeder na potrzeby środowiska deweloperskiego.',
                    // Plakatow nie generujemy - frontend pokaze placeholder.
                    'poster_path' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}
