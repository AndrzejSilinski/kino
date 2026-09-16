<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Halls;

use App\Exceptions\InvalidHallLayoutException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Hall;
use App\Models\PriceCategory;
use App\Services\Admin\HallLayoutGenerator;
use App\Services\Admin\HallLayoutService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edytor układu sali (Etap 7, blok E).
 *
 * PODZIAŁ PRACY: klikanie w siatkę obsługuje Alpine w przeglądarce (bez żądania
 * na każdy fotel — sala IMAX to ponad 300 kratek). Serwer dostaje cały układ
 * dopiero przy zapisie i waliduje go w całości (HallLayoutService). Stan
 * przeglądarki to tylko szkic: nic, co przyjdzie z klienta, nie jest zapisywane
 * bez sprawdzenia.
 *
 * Komponent nie trzyma układu we właściwościach publicznych — migawka Livewire
 * byłaby duża i wędrowała przy każdym żądaniu. Układ trafia do Alpine raz
 * (render) i wraca przez argument save().
 */
final class HallLayoutEditor extends Component
{
    #[Locked]
    public int $hallId;

    public int $rows = 8;

    public int $seatsPerRow = 12;

    /** Numery miejsc, po których jest przejście, np. "4, 12". */
    public string $aislesAfter = '';

    public ?int $categoryId = null;

    public bool $doubleLastRow = false;

    public bool $accessibleEdges = true;

    public ?string $notice = null;

    public ?string $problem = null;

    /** @var list<string> */
    public array $problems = [];

    public function mount(Hall $hall): void
    {
        $this->authorize('update', $hall);

        $this->hallId = $hall->id;
        $this->categoryId = $this->categories()->firstWhere('slug', 'standard')['id']
            ?? $this->categories()->first()['id']
            ?? null;
    }

    /**
     * Szkic układu z generatora. Zwraca miejsca do Alpine albo null przy błędach
     * (Livewire przekazuje wartość zwróconą przez akcję do JavaScriptu).
     *
     * @return list<array<string, mixed>>|null
     */
    public function generate(HallLayoutService $layouts, HallLayoutGenerator $generator): ?array
    {
        $hall = Hall::query()->findOrFail($this->hallId);
        $this->authorize('update', $hall);
        $this->resetMessages();

        if ($layouts->mode($hall) !== HallLayoutService::MODE_FULL) {
            $this->problem = StructureChangeBlockedException::hallLayoutRestricted()->getMessage();

            return null;
        }

        $this->validate([
            'rows' => ['required', 'integer', 'min:1', 'max:'.HallLayoutGenerator::MAX_ROWS],
            'seatsPerRow' => ['required', 'integer', 'min:1', 'max:'.HallLayoutGenerator::MAX_COLUMNS],
            'categoryId' => ['required', 'integer', 'exists:price_categories,id'],
            'aislesAfter' => ['nullable', 'string', 'max:100', 'regex:/\A\s*\d+(\s*,\s*\d+)*\s*\z/'],
        ], [
            'aislesAfter.regex' => 'Podaj numery miejsc oddzielone przecinkami, np. 4, 12.',
        ], [
            'rows' => 'liczba rzędów',
            'seatsPerRow' => 'miejsca w rzędzie',
            'categoryId' => 'kategoria',
            'aislesAfter' => 'przejścia',
        ]);

        $aisles = $this->aislesAfter === '' ? [] : array_map('intval', preg_split('/\s*,\s*/', trim($this->aislesAfter)));

        foreach ($aisles as $aisle) {
            if ($aisle < 1 || $aisle >= $this->seatsPerRow) {
                $this->addError('aislesAfter', "Przejście po miejscu {$aisle} jest poza rzędem (1–".($this->seatsPerRow - 1).').');

                return null;
            }
        }

        if ($this->seatsPerRow + count(array_unique($aisles)) > HallLayoutGenerator::MAX_COLUMNS) {
            $this->addError('seatsPerRow', 'Rząd razem z przejściami może mieć najwyżej '.HallLayoutGenerator::MAX_COLUMNS.' kratek.');

            return null;
        }

        return $generator->generate(
            $this->rows,
            $this->seatsPerRow,
            $aisles,
            (int) $this->categoryId,
            $this->doubleLastRow,
            $this->accessibleEdges,
        );
    }

    /** @param array<int, mixed> $seats układ z edytora — walidowany w całości przez serwis */
    public function save(array $seats, HallLayoutService $layouts): void
    {
        $hall = Hall::query()->findOrFail($this->hallId);
        $this->authorize('update', $hall);
        $this->resetMessages();

        try {
            $hall = $layouts->replace($hall, $seats);
        } catch (InvalidHallLayoutException $e) {
            $this->problem = $e->getMessage();
            $this->problems = $e->problems;

            return;
        } catch (StructureChangeBlockedException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $active = collect($layouts->currentSeats($hall))->where('active', true)->count();
        $this->notice = "Zapisano układ: {$active} aktywnych miejsc, siatka {$hall->grid_rows} × {$hall->grid_cols}.";

        // Alpine przejmuje stan z bazy: nadane rzędy i numery, identyfikatory nowych miejsc.
        $this->dispatch('layout-saved', seats: $layouts->currentSeats($hall), mode: $layouts->mode($hall));
    }

    public function render(): View
    {
        $layouts = app(HallLayoutService::class);
        $hall = Hall::query()->with('cinema')->findOrFail($this->hallId);

        return view('livewire.admin.halls.layout', [
            'hall' => $hall,
            'categories' => $this->categories(),
            'config' => [
                'mode' => $layouts->mode($hall),
                'seats' => $layouts->currentSeats($hall),
                'categories' => $this->categories()->values()->all(),
                'categoryId' => $this->categoryId,
                'maxRows' => HallLayoutGenerator::MAX_ROWS,
                'maxColumns' => HallLayoutGenerator::MAX_COLUMNS,
            ],
        ])->title('Układ: '.$hall->name);
    }

    /** @return Collection<int, array{id: int, slug: string, name: string, color: string}> */
    private function categories(): Collection
    {
        return PriceCategory::query()
            ->ordered()
            ->get(['id', 'slug', 'name', 'color'])
            ->map(fn (PriceCategory $category): array => $category->only(['id', 'slug', 'name', 'color']));
    }

    private function resetMessages(): void
    {
        $this->notice = null;
        $this->problem = null;
        $this->problems = [];
        $this->resetValidation();
    }
}
