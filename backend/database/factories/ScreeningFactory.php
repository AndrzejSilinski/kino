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
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
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

    /**
     * Zeruje licznik przed każdym testem (woła go Tests\TestCase::setUp).
     *
     * Bez tego licznik rósł przez CAŁY przebieg testów: każdy nowy test tworzący
     * seanse przesuwał seanse kolejnych testów o 6 h, aż wypadały poza 14-dniowy
     * horyzont kalendarza (Etap 7: CatalogApiTest po dodaniu testów filmów).
     * Baza jest czyszczona po każdym teście, więc sloty mogą zaczynać się od zera.
     */
    public static function resetSlots(): void
    {
        static::$slot = 0;
    }

    public function definition(): array
    {
        // Etap 7: czasy z ScreeningTimeline, jak w panelu i seederze. Film fabryki
        // ma stałe 120 minut (MovieFactory), więc okna slotów są przewidywalne.
        $slot = ScreeningTimeline::fromConfig()->slot(
            CarbonImmutable::now()->addDay()->startOfHour()->addHours(6 * static::$slot++),
            120,
        );

        return [
            'movie_id' => Movie::factory(),
            'hall_id' => Hall::factory(),
            'starts_at' => $slot->startsAt,
            'ends_at' => $slot->endsAt,
            'slot_ends_at' => $slot->slotEndsAt,
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
