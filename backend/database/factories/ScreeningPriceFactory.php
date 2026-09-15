<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningPrice>
 */
class ScreeningPriceFactory extends Factory
{
    protected $model = ScreeningPrice::class;

    public function definition(): array
    {
        return [
            'screening_id' => Screening::factory(),
            'price_category_id' => PriceCategory::factory(),
            // Cena w GROSZACH — 2500 to 25,00 zł. Nigdy float:
            // 0.1 + 0.2 !== 0.3 w arytmetyce zmiennoprzecinkowej, a przy
            // sumowaniu koszyka takie błędy się kumulują. Stripe też przyjmuje
            // jednostki minorowe, więc konwersja jest zbędna.
            'price' => 2500,
        ];
    }
}
