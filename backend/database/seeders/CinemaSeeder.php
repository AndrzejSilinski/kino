<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SeatType;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Seat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CinemaSeeder extends Seeder
{
    /**
     * Szablony ukladow sal. "aisles_after" to numery miejsc, po ktorych
     * na siatce planu robi sie przejscie - dlatego position_x nie jest
     * rowne seat_number.
     */
    private const LAYOUTS = [
        'small'  => ['rows' => 8,  'seats_per_row' => 12, 'aisles_after' => [6]],
        'medium' => ['rows' => 12, 'seats_per_row' => 16, 'aisles_after' => [4, 12]],
        'imax'   => ['rows' => 16, 'seats_per_row' => 22, 'aisles_after' => [6, 16]],
    ];

    private const CINEMAS = [
        [
            'name' => 'Kino Atlantyk',
            'city' => 'Warszawa',
            'address' => 'ul. Złota 44',
            'halls' => [
                ['name' => 'Sala 1',    'layout' => 'medium', 'projection_types' => ['2d', '3d']],
                ['name' => 'Sala 2',    'layout' => 'small',  'projection_types' => ['2d']],
                ['name' => 'Sala IMAX', 'layout' => 'imax',   'projection_types' => ['2d', '3d', 'imax']],
            ],
        ],
        [
            'name' => 'Kino Wisła',
            'city' => 'Kraków',
            'address' => 'ul. Karmelicka 12',
            'halls' => [
                ['name' => 'Sala Czerwona', 'layout' => 'medium', 'projection_types' => ['2d', '3d']],
                ['name' => 'Sala Niebieska', 'layout' => 'small', 'projection_types' => ['2d']],
            ],
        ],
        [
            'name' => 'Kino Bałtyk',
            'city' => 'Gdańsk',
            'address' => 'al. Grunwaldzka 82',
            'halls' => [
                ['name' => 'Sala A', 'layout' => 'small',  'projection_types' => ['2d']],
                ['name' => 'Sala B', 'layout' => 'medium', 'projection_types' => ['2d', '3d']],
            ],
        ],
    ];

    /** @var array<string, int> mapa slug kategorii cenowej -> id */
    private array $categoryIds = [];

    public function run(): void
    {
        $this->categoryIds = PriceCategory::pluck('id', 'slug')->all();

        foreach (self::CINEMAS as $spec) {
            $cinema = Cinema::updateOrCreate(
                ['slug' => Str::slug($spec['city'].' '.$spec['name'])],
                [
                    'name' => $spec['name'],
                    'city' => $spec['city'],
                    'address' => $spec['address'],
                    'timezone' => 'Europe/Warsaw',
                    'is_active' => true,
                ]
            );

            foreach ($spec['halls'] as $hallSpec) {
                $this->createHall($cinema, $hallSpec);
            }
        }
    }

    /**
     * @param array{name: string, layout: string, projection_types: array<int, string>} $spec
     */
    private function createHall(Cinema $cinema, array $spec): void
    {
        $layout = self::LAYOUTS[$spec['layout']];

        // Siatka jest szersza niz liczba miejsc w rzedzie o liczbe przejsc.
        $gridCols = $layout['seats_per_row'] + count($layout['aisles_after']);

        $hall = Hall::updateOrCreate(
            ['cinema_id' => $cinema->id, 'name' => $spec['name']],
            [
                'projection_types' => $spec['projection_types'],
                'grid_rows' => $layout['rows'],
                'grid_cols' => $gridCols,
                'is_active' => true,
            ]
        );

        // Odtwarzamy uklad od zera. Zadziala tylko na swiezej bazie - miejsca
        // wskazywane przez sprzedane bilety sa chronione przez ON DELETE RESTRICT.
        $hall->seats()->delete();

        // insert() zamiast create() w petli: jeden INSERT zamiast ~200 zapytan.
        // Timestampy trzeba wtedy ustawic recznie, bo omijamy Eloquenta.
        Seat::insert($this->buildSeats($hall, $layout, $spec['layout'] === 'imax'));
    }

    /**
     * @param array{rows: int, seats_per_row: int, aisles_after: array<int, int>} $layout
     * @return array<int, array<string, mixed>>
     */
    private function buildSeats(Hall $hall, array $layout, bool $hasVipRow): array
    {
        $now = now();
        $seats = [];

        $rowCount = $layout['rows'];
        $perRow = $layout['seats_per_row'];
        $aisles = $layout['aisles_after'];

        for ($r = 0; $r < $rowCount; $r++) {
            $rowLabel = chr(65 + $r);   // A, B, C, ...
            $y = $r + 1;

            // Ostatni rzad to kanapy dla par: polowa miejsc, kazde szerokie
            // na dwie kratki siatki.
            if ($r === $rowCount - 1) {
                for ($i = 1; $i <= intdiv($perRow, 2); $i++) {
                    $seats[] = [
                        'hall_id' => $hall->id,
                        'price_category_id' => $this->categoryIds['love'],
                        'row_label' => $rowLabel,
                        'seat_number' => $i,
                        'type' => SeatType::Double->value,
                        'position_x' => $i * 2 - 1,
                        'position_y' => $y,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                continue;
            }

            // W salach IMAX przedostatni rzad jest strefa VIP.
            $isVipRow = $hasVipRow && $r === $rowCount - 2;

            // Tylna czesc sali (od 40% glebokosci) to Premium.
            $isPremiumRow = ! $isVipRow && $r >= (int) floor($rowCount * 0.4);

            for ($n = 1; $n <= $perRow; $n++) {
                // Kazde minięte przejscie przesuwa miejsce na siatce w prawo.
                $offset = count(array_filter($aisles, static fn (int $a): bool => $n > $a));

                // Miejsca dla osob z niepelnosprawnoscia: skrajne w pierwszym
                // rzedzie, najblizej wejscia i bez schodow.
                $isAccessible = $r === 0 && ($n === 1 || $n === $perRow);

                $categorySlug = match (true) {
                    $isAccessible => 'accessible',
                    $isVipRow => 'vip',
                    $isPremiumRow => 'premium',
                    default => 'standard',
                };

                $seats[] = [
                    'hall_id' => $hall->id,
                    'price_category_id' => $this->categoryIds[$categorySlug],
                    'row_label' => $rowLabel,
                    'seat_number' => $n,
                    'type' => $isAccessible ? SeatType::Accessible->value : SeatType::Standard->value,
                    'position_x' => $n + $offset,
                    'position_y' => $y,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $seats;
    }
}
