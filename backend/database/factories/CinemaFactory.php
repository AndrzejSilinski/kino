<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cinema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cinema>
 */
class CinemaFactory extends Factory
{
    protected $model = Cinema::class;

    public function definition(): array
    {
        $city = fake()->randomElement(['Warszawa', 'Kraków', 'Gdańsk', 'Wrocław', 'Poznań', 'Łódź']);
        $name = 'Kino '.fake()->randomElement(['Atlantic', 'Muranów', 'Iluzjon', 'Kinoteka', 'Helios', 'Luna']);

        return [
            'name' => $name,
            // Slug musi być unikalny. NIE używamy fake()->unique(), bo ono trzyma
            // listę użytych wartości w pamięci i po wyczerpaniu puli rzuca
            // OverflowException — w teście tworzącym 50 kin to realny problem.
            // Losowy sufiks daje unikalność bez limitu.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'city' => $city,
            'address' => fake()->streetAddress(),
            'timezone' => 'Europe/Warsaw',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
