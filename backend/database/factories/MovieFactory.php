<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    protected $model = Movie::class;

    public function definition(): array
    {
        $title = Str::title(fake()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'original_title' => $title,
            'description' => fake()->paragraph(),
            // 120 minut, a nie losowa wartość: ScreeningFactory wylicza z tego
            // ends_at i slot_ends_at. Stała długość sprawia, że okna czasowe
            // seansów są przewidywalne i nie wpadają na constraint EXCLUDE.
            'duration_minutes' => 120,
            'poster_path' => null,
            'age_rating' => fake()->randomElement(['G', '7+', '13+', '16+', '18+']),
            'genres' => ['dramat', 'thriller'],
            'premiere_date' => now()->subDays(fake()->numberBetween(0, 60))->toDateString(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
