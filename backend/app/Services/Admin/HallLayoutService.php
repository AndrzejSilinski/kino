<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ScreeningStatus;
use App\Enums\SeatType;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidHallLayoutException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Services\SeatStateRecorder;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Zapis układu sali z edytora (Etap 7, blok E).
 *
 * DWA TRYBY, bo miejsca są wskazywane kluczami obcymi przez bilety i blokady:
 *
 * PEŁNY — sala bez historii sprzedaży (żadnego biletu ani blokady na jej miejscach)
 *   i bez nadchodzących seansów. Układ zastępujemy w całości: DELETE + INSERT,
 *   rzędy i numery nadajemy od nowa. Nic nie wskazuje starych miejsc, a brak
 *   nadchodzących seansów wyklucza wyścig z klientem blokującym właśnie usuwany fotel.
 *
 * OGRANICZONY — w pozostałych przypadkach tożsamość miejsc jest zamrożona:
 *   te same id, pozycje, rzędy, numery i szerokość (podwójne zostaje podwójnym).
 *   Wolno zmienić kategorię, typ standard <-> dla niepełnosprawnych i dostępność.
 *   Bilet z przeszłości musi dalej wskazywać "B7", a nie fotel przestawiony
 *   w inne miejsce. Przebudowa sali z historią = nowa sala.
 *
 * STRAŻNICY SPRZEDAŻY (tryb ograniczony, tylko nadchodzące seanse):
 *   - kategoria i wyłączenie miejsca z aktywną blokadą albo oczekującą płatnością
 *     -> 409: fulfil() porównuje sumę cen z kwotą rezerwacji (decyzja 37),
 *   - wyłączenie sprzedanego miejsca -> 409: klient ma na nie bilet,
 *   - każda kategoria aktywnych miejsc musi mieć cenę w cenniku każdego
 *     nadchodzącego seansu -> 409, inaczej sprzedaż skończy się PRICE_NOT_CONFIGURED.
 *
 * Po zapisie: SeatsResync dla nadchodzących seansów (klienci pobiorą plan od nowa)
 * i generacja kina w cache (liczba aktywnych miejsc jest w repertuarze).
 */
final class HallLayoutService
{
    public const MODE_FULL = 'full';

    public const MODE_RESTRICTED = 'restricted';

    private const MAX_PROBLEMS = 20;

    public function __construct(
        private readonly CatalogCache $catalog,
        private readonly SeatStateRecorder $seatStates,
    ) {}

    public function mode(Hall $hall): string
    {
        $seatIds = Seat::query()->select('id')->where('hall_id', $hall->id);

        $hasHistory = Ticket::query()->whereIn('seat_id', $seatIds)->exists()
            || SeatLock::query()->whereIn('seat_id', $seatIds)->exists();

        return $hasHistory || $this->upcomingScreeningIds($hall) !== []
            ? self::MODE_RESTRICTED
            : self::MODE_FULL;
    }

    /**
     * Miejsca sali w formacie edytora.
     *
     * @return list<array{id: int, x: int, y: int, type: string, category_id: int, active: bool, label: string}>
     */
    public function currentSeats(Hall $hall): array
    {
        return Seat::query()
            ->where('hall_id', $hall->id)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get()
            ->map(fn (Seat $seat): array => [
                'id' => $seat->id,
                'x' => $seat->position_x,
                'y' => $seat->position_y,
                'type' => $seat->type->value,
                'category_id' => $seat->price_category_id,
                'active' => $seat->is_active,
                'label' => $seat->label,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $seats  miejsca z edytora: id, x, y, type, category_id, active
     *
     * @throws InvalidHallLayoutException gdy układ jest wewnętrznie sprzeczny (422)
     * @throws StructureChangeBlockedException gdy zmiana koliduje z historią lub sprzedażą (409)
     */
    public function replace(Hall $hall, array $seats): Hall
    {
        // Walidacja treści przed transakcją: jest tania i nie potrzebuje blokad.
        $layout = $this->normalize($seats);

        return DB::transaction(function () use ($hall, $layout): Hall {
            // Blokada sali szereguje zapisy układu (i w bloku G — dodawanie seansów).
            $fresh = Hall::query()->whereKey($hall->id)->lockForUpdate()->firstOrFail();
            $upcoming = $this->upcomingScreeningIds($fresh);

            if ($this->mode($fresh) === self::MODE_FULL) {
                $this->replaceAll($fresh, $layout);
            } else {
                $this->updateInPlace($fresh, $layout, $upcoming);
            }

            $this->assertPricesCoverActiveSeats($fresh, $upcoming);

            [$fresh->grid_rows, $fresh->grid_cols] = $this->gridSize($layout);
            $fresh->save();

            // Rosnąco po id seansu — ta sama kolejność liczników co w sweepie (Etap 6).
            foreach ($upcoming as $screeningId) {
                $this->seatStates->recordLayoutChange($screeningId);
            }

            $this->catalog->bump(CatalogCache::cinema($fresh->cinema_id));

            return $fresh;
        });
    }

    /**
     * @param  list<array{id: ?int, x: int, y: int, type: SeatType, category_id: int, active: bool}>  $layout
     */
    private function replaceAll(Hall $hall, array $layout): void
    {
        Seat::query()->where('hall_id', $hall->id)->delete();

        $now = CarbonImmutable::now();
        $labels = $this->labels($layout);
        $rows = [];

        foreach ($layout as $index => $seat) {
            $rows[] = [
                'hall_id' => $hall->id,
                'price_category_id' => $seat['category_id'],
                'row_label' => $labels[$index]['row'],
                'seat_number' => $labels[$index]['number'],
                'type' => $seat['type']->value,
                'position_x' => $seat['x'],
                'position_y' => $seat['y'],
                'is_active' => $seat['active'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Jeden INSERT zamiast kilkuset — tak jak CinemaSeeder.
        Seat::query()->insert($rows);
    }

    /**
     * @param  list<array{id: ?int, x: int, y: int, type: SeatType, category_id: int, active: bool}>  $layout
     * @param  list<int>  $upcoming
     */
    private function updateInPlace(Hall $hall, array $layout, array $upcoming): void
    {
        $existing = Seat::query()
            ->where('hall_id', $hall->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $submitted = [];

        foreach ($layout as $seat) {
            $current = $seat['id'] !== null ? $existing->get($seat['id']) : null;

            $sameIdentity = $current !== null
                && $current->position_x === $seat['x']
                && $current->position_y === $seat['y']
                && ($current->type === SeatType::Double) === ($seat['type'] === SeatType::Double);

            if (! $sameIdentity) {
                throw StructureChangeBlockedException::hallLayoutRestricted();
            }

            $submitted[$current->id] = $seat;
        }

        if (count($submitted) !== $existing->count()) {
            throw StructureChangeBlockedException::hallLayoutRestricted();
        }

        $changed = [];
        $sensitive = [];
        $deactivated = [];

        foreach ($submitted as $id => $seat) {
            $current = $existing->get($id);
            $categoryChanged = $current->price_category_id !== $seat['category_id'];
            $activeChanged = $current->is_active !== $seat['active'];

            if (! $categoryChanged && ! $activeChanged && $current->type === $seat['type']) {
                continue;
            }

            $changed[$id] = $seat;

            if ($categoryChanged || ($activeChanged && ! $seat['active'])) {
                $sensitive[] = $id;
            }

            if ($activeChanged && ! $seat['active']) {
                $deactivated[] = $id;
            }
        }

        if ($upcoming !== [] && $sensitive !== []) {
            $held = SeatLock::query()
                ->whereIn('screening_id', $upcoming)
                ->whereIn('seat_id', $sensitive)
                ->whereNull('released_at')
                ->distinct()
                ->pluck('seat_id')
                ->all();

            if ($held !== []) {
                throw StructureChangeBlockedException::hallLayoutSeatsHeld($this->labelsOf($existing, $held));
            }
        }

        if ($upcoming !== [] && $deactivated !== []) {
            $sold = Ticket::query()
                ->whereIn('screening_id', $upcoming)
                ->whereIn('seat_id', $deactivated)
                ->where('status', '!=', TicketStatus::Cancelled->value)
                ->distinct()
                ->pluck('seat_id')
                ->all();

            if ($sold !== []) {
                throw StructureChangeBlockedException::hallLayoutSeatsSold($this->labelsOf($existing, $sold));
            }
        }

        foreach ($changed as $id => $seat) {
            $current = $existing->get($id);
            $current->price_category_id = $seat['category_id'];
            $current->type = $seat['type'];
            $current->is_active = $seat['active'];
            $current->save();
        }
    }

    /** @param list<int> $upcoming */
    private function assertPricesCoverActiveSeats(Hall $hall, array $upcoming): void
    {
        if ($upcoming === []) {
            return;
        }

        $needed = Seat::query()
            ->where('hall_id', $hall->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('price_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $priced = ScreeningPrice::query()
            ->whereIn('screening_id', $upcoming)
            ->get(['screening_id', 'price_category_id'])
            ->groupBy('screening_id');

        $missing = [];
        $screenings = 0;

        foreach ($upcoming as $screeningId) {
            $have = $priced->get($screeningId, collect())->pluck('price_category_id')->map(fn ($id): int => (int) $id)->all();
            $lacking = array_diff($needed, $have);

            if ($lacking !== []) {
                $screenings++;
                $missing = array_merge($missing, $lacking);
            }
        }

        if ($missing !== []) {
            $names = PriceCategory::query()->whereIn('id', array_unique($missing))->orderBy('sort_order')->pluck('name')->all();

            throw StructureChangeBlockedException::hallLayoutPricesMissing($names, $screenings);
        }
    }

    /** @return list<int> nadchodzące (także trwające) seanse sali, rosnąco po id */
    private function upcomingScreeningIds(Hall $hall): array
    {
        return Screening::query()
            ->where('hall_id', $hall->id)
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '>', CarbonImmutable::now())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Rzędy i numery jak w CinemaSeeder: rzędy z miejscami po y dostają kolejne
     * litery (puste rzędy siatki nie zabierają liter), numery po x od lewej.
     *
     * @param  list<array{x: int, y: int}>  $layout  posortowany po y, potem x
     * @return array<int, array{row: string, number: int}>
     */
    private function labels(array $layout): array
    {
        $rowIndex = array_flip(array_values(array_unique(array_column($layout, 'y'))));
        $numbers = [];
        $labels = [];

        foreach ($layout as $index => $seat) {
            $numbers[$seat['y']] = ($numbers[$seat['y']] ?? 0) + 1;
            $labels[$index] = ['row' => chr(65 + $rowIndex[$seat['y']]), 'number' => $numbers[$seat['y']]];
        }

        return $labels;
    }

    /**
     * @param  list<array{x: int, y: int, type: SeatType}>  $layout
     * @return array{0: int, 1: int}
     */
    private function gridSize(array $layout): array
    {
        $rows = 0;
        $cols = 0;

        foreach ($layout as $seat) {
            $rows = max($rows, $seat['y']);
            $cols = max($cols, $seat['x'] + ($seat['type'] === SeatType::Double ? 1 : 0));
        }

        return [$rows, $cols];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Seat>  $seats
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function labelsOf($seats, array $ids): array
    {
        return collect($ids)->map(fn (int $id): string => $seats->get($id)->label)->sort()->values()->all();
    }

    /**
     * Sprawdza treść układu i sprowadza go do typów PHP, posortowany po y, potem x.
     * Zbiera WSZYSTKIE problemy (do MAX_PROBLEMS), a nie tylko pierwszy.
     *
     * @param  array<int, mixed>  $seats
     * @return list<array{id: ?int, x: int, y: int, type: SeatType, category_id: int, active: bool}>
     *
     * @throws InvalidHallLayoutException
     */
    private function normalize(array $seats): array
    {
        $problems = [];
        $layout = [];
        $cells = [];
        $ids = [];

        if (count($seats) > HallLayoutGenerator::MAX_ROWS * HallLayoutGenerator::MAX_COLUMNS) {
            throw new InvalidHallLayoutException(['Za dużo miejsc w układzie.']);
        }

        foreach (array_values($seats) as $index => $raw) {
            $where = 'Miejsce nr '.($index + 1);

            if (! is_array($raw)) {
                $problems[] = "{$where}: nieprawidłowy format.";

                continue;
            }

            $x = filter_var($raw['x'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => HallLayoutGenerator::MAX_COLUMNS]]);
            $y = filter_var($raw['y'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => HallLayoutGenerator::MAX_ROWS]]);
            $type = is_string($raw['type'] ?? null) ? SeatType::tryFrom($raw['type']) : null;
            $category = filter_var($raw['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $active = $raw['active'] ?? null;
            $id = ($raw['id'] ?? null) === null ? null : filter_var($raw['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($x === false || $y === false || $type === null || $category === false || ! is_bool($active) || $id === false) {
                $problems[] = "{$where}: brak albo zła wartość pozycji, typu, kategorii lub dostępności.";

                continue;
            }

            if ($id !== null && isset($ids[$id])) {
                $problems[] = "{$where}: powtórzony identyfikator miejsca.";
            }

            $ids[$id ?? 'nowe-'.$index] = true;
            $width = $type === SeatType::Double ? 2 : 1;

            if ($x + $width - 1 > HallLayoutGenerator::MAX_COLUMNS) {
                $problems[] = "Miejsce podwójne w rzędzie {$y} wychodzi poza siatkę ({$x}. kratka).";

                continue;
            }

            for ($cell = $x; $cell < $x + $width; $cell++) {
                if (isset($cells["{$cell}:{$y}"])) {
                    $problems[] = "Miejsca nachodzą na siebie w rzędzie siatki {$y}, kratka {$cell}.";
                }

                $cells["{$cell}:{$y}"] = true;
            }

            $layout[] = ['id' => $id, 'x' => $x, 'y' => $y, 'type' => $type, 'category_id' => $category, 'active' => $active];
        }

        $categories = array_unique(array_column($layout, 'category_id'));
        $known = PriceCategory::query()->whereIn('id', $categories)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_diff($categories, $known) as $unknown) {
            $problems[] = "Nieznana kategoria cenowa (id {$unknown}).";
        }

        if (! in_array(true, array_column($layout, 'active'), true)) {
            $problems[] = 'Układ musi mieć co najmniej jedno aktywne miejsce.';
        }

        if ($problems !== []) {
            $problems = array_values(array_unique($problems));
            $extra = count($problems) - self::MAX_PROBLEMS;

            throw new InvalidHallLayoutException($extra > 0
                ? [...array_slice($problems, 0, self::MAX_PROBLEMS), "…i {$extra} kolejnych."]
                : $problems);
        }

        usort($layout, static fn (array $a, array $b): int => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        return $layout;
    }
}
