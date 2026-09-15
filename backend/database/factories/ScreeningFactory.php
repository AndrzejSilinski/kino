<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Screening>
 */
class ScreeningFactory extends Factory
{
    protected $model = Screening::class;

    /**
     * Licznik slotów czasowych.
     *
     * PO CO: tabela screenings ma ograniczenie
     *     EXCLUDE USING gist (hall_id WITH =, tstzrange(starts_at, slot_ends_at) WITH &&)
     * czyli baza fizycznie nie pozwoli wstawić dwóch nakładających się seansów w tej
     * samej sali. Losowe godziny zderzyłyby się bardzo szybko. Każdy kolejny seans
     * przesuwamy o 6 godzin, a film trwa 120 minut + reklamy + sprzątanie ≈ 2h35,
     * więc okna nigdy się nie nakładają.
     */
    protected static int $slot = 0;

    public function definition(): array
    {
        $ads = (int) config('cinema.screening.ads_minutes', 15);
        $cleanup = (int) config('cinema.screening.cleanup_buffer_minutes', 20);

        $startsAt = Carbon::now()->addDay()->startOfHour()->addHours(6 * static::$slot++);
        $endsAt = $startsAt->copy()->addMinutes(120 + $ads);
        $slotEndsAt = $endsAt->copy()->addMinutes($cleanup);

        return [
            'movie_id' => Movie::factory(),
            'hall_id' => Hall::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'slot_ends_at' => $slotEndsAt,
            'projection_type' => ProjectionType::from('2d'),
            'language_version' => LanguageVersion::from('subtitles'),
            'status' => ScreeningStatus::from('scheduled'),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => ScreeningStatus::from('cancelled')]);
    }

    public function finished(): static
    {
        return $this->state(fn (): array => ['status' => ScreeningStatus::from('finished')]);
    }

    /**
     * Seans, który już się zaczął. Uwaga: ograniczenie EXCLUDE nie patrzy na to,
     * czy seans jest w przeszłości — tylko na nakładanie się okien w tej samej sali.
     */
    public function started(): static
    {
        return $this->state(function (): array {
            $startsAt = Carbon::now()->subMinutes(30);

            return [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(135),
                'slot_ends_at' => $startsAt->copy()->addMinutes(155),
            ];
        });
    }

    /** Cennik dla wszystkich kategorii występujących na miejscach tej sali. */
    public function withPrices(int $price = 2500): static
    {
        return $this->afterCreating(function (Screening $screening) use ($price): void {
            $categoryIds = $screening->hall->seats()->distinct()->pluck('price_category_id');

            if ($categoryIds->isEmpty()) {
                $categoryIds = collect([PriceCategory::factory()->create()->id]);
            }

            foreach ($categoryIds as $categoryId) {
                ScreeningPrice::factory()->create([
                    'screening_id' => $screening->id,
                    'price_category_id' => $categoryId,
                    'price' => $price,
                ]);
            }
        });
    }
}
