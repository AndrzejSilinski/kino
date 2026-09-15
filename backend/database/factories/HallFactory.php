<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cinema;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hall>
 */
class HallFactory extends Factory
{
    protected $model = Hall::class;

    /** Nazwa sali jest UNIQUE w obrębie kina — licznik gwarantuje różne nazwy. */
    protected static int $counter = 0;

    public function definition(): array
    {
        return [
            'cinema_id' => Cinema::factory(),
            'name' => 'Sala '.(++static::$counter),
            // Wartości surowe, nie instancje enuma: kolumna jest typu jsonb,
            // a taki zapis działa niezależnie od tego, czy model rzutuje ją
            // na zwykłą tablicę, czy na kolekcję enumów.
            'projection_types' => ['2d', '3d'],
            'grid_rows' => 5,
            'grid_cols' => 8,
            'is_active' => true,
        ];
    }

    /**
     * Buduje salę z gotową, uporządkowaną siatką miejsc: A1..A{cols}, B1..B{cols}, ...
     *
     * To jest najważniejsza metoda całego zestawu factories — praktycznie każdy test
     * blokowania miejsc zaczyna się od Hall::factory()->withSeats(2, 5)->create().
     * Wszystkie miejsca dostają jedną kategorię cenową, bo testy współbieżności
     * nie sprawdzają cen, a mniej wierszy to szybszy test.
     */
    public function withSeats(int $rows = 3, int $cols = 8, ?PriceCategory $category = null): static
    {
        return $this
            ->state(fn (): array => ['grid_rows' => $rows, 'grid_cols' => $cols])
            ->afterCreating(function (Hall $hall) use ($rows, $cols, $category): void {
                $category ??= PriceCategory::factory()->create();

                for ($r = 0; $r < $rows; $r++) {
                    for ($c = 1; $c <= $cols; $c++) {
                        Seat::factory()->create([
                            'hall_id' => $hall->id,
                            'price_category_id' => $category->id,
                            'row_label' => chr(65 + $r),
                            'seat_number' => $c,
                            'position_x' => $c,
                            'position_y' => $r + 1,
                        ]);
                    }
                }
            });
    }
}
