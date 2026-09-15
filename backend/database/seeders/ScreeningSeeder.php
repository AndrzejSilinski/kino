<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ScreeningSeeder extends Seeder
{
    /** Ceny bazowe w groszach dla seansu 2D w dzien powszedni po poludniu. */
    private const BASE_PRICES = [
        'standard'   => 2200,
        'premium'    => 2800,
        'vip'        => 3900,
        'love'       => 4000,
        'accessible' => 1600,
    ];

    /** Repertuar od dwoch dni wstecz (dane do dashboardu) do 13 dni w przod. */
    private const DAYS_BACK = 2;
    private const DAYS_AHEAD = 13;

    private const FIRST_SHOW_HOUR = 11;
    private const LAST_SHOW_HOUR = 22;

    /** @var array<string, int> */
    private array $categoryIds = [];

    private int $rotation = 0;

    public function run(): void
    {
        $this->categoryIds = PriceCategory::pluck('id', 'slug')->all();

        $movies = Movie::active()->orderBy('id')->get();
        $adsMinutes = (int) config('cinema.screening.ads_minutes');
        $bufferMinutes = (int) config('cinema.screening.cleanup_buffer_minutes');

        foreach (Cinema::with('halls')->get() as $cinema) {
            foreach ($cinema->halls as $hall) {
                for ($day = -self::DAYS_BACK; $day <= self::DAYS_AHEAD; $day++) {
                    $date = CarbonImmutable::now($cinema->timezone)->addDays($day)->startOfDay();

                    $cursor = $date->setTime(self::FIRST_SHOW_HOUR, 0);
                    $lastStart = $date->setTime(self::LAST_SHOW_HOUR, 0);

                    while ($cursor <= $lastStart) {
                        $movie = $movies[$this->rotation % $movies->count()];
                        $projection = $this->pickProjection($hall);
                        $this->rotation++;

                        $endsAt = $cursor->addMinutes($adsMinutes + $movie->duration_minutes);
                        $slotEndsAt = $endsAt->addMinutes($bufferMinutes);

                        $screening = Screening::create([
                            'movie_id' => $movie->id,
                            'hall_id' => $hall->id,
                            'starts_at' => $cursor,
                            'ends_at' => $endsAt,
                            'slot_ends_at' => $slotEndsAt,
                            'projection_type' => $projection,
                            'language_version' => $this->pickLanguage($movie->age_rating),
                            'status' => $slotEndsAt->isPast() ? ScreeningStatus::Finished : ScreeningStatus::Scheduled,
                        ]);

                        $this->createPrices($screening, $cursor, $projection);

                        // Kolejny seans moze zaczac sie dopiero po zwolnieniu sali.
                        // Dzieki temu constraint screenings_no_overlap nigdy nie
                        // zostanie naruszony - seeder sam respektuje regule.
                        $cursor = $this->ceilToQuarter($slotEndsAt);
                    }
                }
            }
        }
    }

    /**
     * Cennik seansu: cena bazowa kategorii przemnozona przez mnozniki
     * pory dnia, dnia tygodnia i technologii projekcji.
     */
    private function createPrices(Screening $screening, CarbonImmutable $startsAt, ProjectionType $projection): void
    {
        $multiplier = 1.0;

        if ($startsAt->isWeekend()) {
            $multiplier += 0.20;
        }

        if ($startsAt->hour >= 17) {
            $multiplier += 0.15;      // seanse wieczorne
        } elseif ($startsAt->hour < 14) {
            $multiplier -= 0.20;      // poranki taniej
        }

        $multiplier *= match ($projection) {
            ProjectionType::TwoD => 1.00,
            ProjectionType::ThreeD => 1.15,
            ProjectionType::Imax => 1.35,
        };

        $now = now();
        $rows = [];

        foreach (self::BASE_PRICES as $slug => $base) {
            $rows[] = [
                'screening_id' => $screening->id,
                'price_category_id' => $this->categoryIds[$slug],
                // Zaokraglenie do pelnych 10 groszy - ceny w kinie nie maja
                // koncowek typu 23,47 zl.
                'price' => (int) (round($base * $multiplier / 10) * 10),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ScreeningPrice::insert($rows);
    }

    /**
     * Rotacja po technologiach, ktore dana sala obsluguje - deterministycznie,
     * zeby kolejne uruchomienia seedera dawaly ten sam repertuar.
     */
    private function pickProjection(Hall $hall): ProjectionType
    {
        $types = $hall->projection_types;

        return ProjectionType::from($types[$this->rotation % count($types)]);
    }

    /**
     * Filmy familijne graja z dubbingiem, reszta z napisami.
     */
    private function pickLanguage(string $ageRating): LanguageVersion
    {
        return $ageRating === 'B/O'
            ? LanguageVersion::Dubbing
            : LanguageVersion::Subtitles;
    }

    /**
     * Zaokraglenie w gore do pelnego kwadransa - kina nie zaczynaja seansow
     * o 19:07.
     */
    private function ceilToQuarter(CarbonImmutable $time): CarbonImmutable
    {
        $time = $time->setSecond(0);
        $remainder = $time->minute % 15;

        return $remainder === 0 ? $time : $time->addMinutes(15 - $remainder);
    }
}
