<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PriceCategory>
 */
class PriceCategoryFactory extends Factory
{
    protected $model = PriceCategory::class;

    public function definition(): array
    {
        return [
            'slug' => 'kategoria-'.Str::lower(Str::random(10)),
            'name' => 'Kategoria testowa',
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Kategoria o konkretnym slugu — potrzebna wszędzie tam, gdzie test sprawdza
     * zachowanie zależne od rodzaju miejsca, np. cenę pakietową love seatów.
     *
     * Uwaga: slug jest UNIQUE, więc tej metody nie wolno wywołać dwa razy z tą samą
     * wartością w jednym teście. To celowo NIE jest firstOrCreate — factory ma tworzyć,
     * a nie zgadywać, czy coś już istnieje.
     */
    public function slug(string $slug): static
    {
        return $this->state(fn (): array => [
            'slug' => $slug,
            'name' => Str::title($slug),
        ]);
    }
}
