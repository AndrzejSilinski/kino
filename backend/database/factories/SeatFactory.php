<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    protected $model = Seat::class;

    /**
     * Licznik współdzielony przez wszystkie wywołania w jednym procesie testowym.
     *
     * PO CO: tabela seats ma DWIE unikalności — (hall_id, row_label, seat_number)
     * oraz (hall_id, position_x, position_y). Losowe wartości zderzyłyby się
     * po kilkunastu wierszach i test padłby z QueryException zamiast pokazać
     * prawdziwy problem. Licznik daje wartości deterministyczne i zawsze różne.
     *
     * Numeracja nie resetuje się między salami — to nie szkodzi, bo unikalność
     * jest liczona w obrębie sali. Uporządkowaną siatkę (A1..A8, B1..B8) buduje
     * HallFactory::withSeats(), które podaje row_label i seat_number jawnie.
     */
    protected static int $counter = 0;

    public function definition(): array
    {
        $index = static::$counter++;
        $row = chr(65 + intdiv($index, 26));   // A, B, C, ...
        $number = ($index % 26) + 1;

        return [
            'hall_id' => Hall::factory(),
            'price_category_id' => PriceCategory::factory(),
            'row_label' => $row,
            'seat_number' => $number,
            'type' => SeatType::Standard,
            'position_x' => $number,
            'position_y' => $index + 1,
            'is_active' => true,
        ];
    }

    public function double(): static
    {
        return $this->state(fn (): array => ['type' => SeatType::Double]);
    }

    public function accessible(): static
    {
        return $this->state(fn (): array => ['type' => SeatType::Accessible]);
    }

    /** Miejsce wyłączone ze sprzedaży — np. uszkodzony fotel. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
