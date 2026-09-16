<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Screenings;

use App\Exceptions\InvalidScreeningException;
use App\Exceptions\RepertoireCopyBlockedException;
use App\Models\Cinema;
use App\Models\Screening;
use App\Services\Admin\RepertoireCopyService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kopiowanie repertuaru kina z dnia na dzień (Etap 7, blok H).
 *
 * Dwa kroki: "Podgląd" (ten sam przebieg co kopiowanie, zakończony ROLLBACK)
 * i "Kopiuj". Przycisk kopiowania jest aktywny tylko dla podglądu tych samych
 * dni bez problemów — ale serwis i tak liczy wszystko od nowa pod blokadami,
 * bo między podglądem a kliknięciem ktoś mógł dodać seans.
 */
final class RepertoireCopy extends Component
{
    #[Locked]
    public int $cinemaId;

    public string $sourceDate = '';

    public string $targetDate = '';

    /** @var array{created: int, existing: int, items: list<array{hall: string, time: string, movie: string, status: string, message: ?string}>}|null */
    public ?array $report = null;

    /** Dla jakich dni jest podgląd — zmiana dat go unieważnia. */
    #[Locked]
    public ?string $previewFor = null;

    public ?string $problem = null;

    public function mount(Cinema $cinema): void
    {
        $this->authorize('create', Screening::class);

        $this->cinemaId = $cinema->id;
        $today = CarbonImmutable::now($cinema->timezone);
        $from = (string) request()->query('z');
        $this->sourceDate = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $from) === 1 ? $from : $today->toDateString();
        $this->targetDate = CarbonImmutable::parse($this->sourceDate)->addDays(7)->max($today->addDay()->startOfDay())->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sourceDate', 'targetDate'], true)) {
            $this->report = null;
            $this->previewFor = null;
            $this->problem = null;
        }
    }

    public function preview(RepertoireCopyService $copies): void
    {
        $cinema = $this->cinema();
        $this->validateDates();
        $this->problem = null;

        try {
            $this->report = $copies->preview($cinema, $this->sourceDate, $this->targetDate);
            $this->previewFor = $this->sourceDate.'>'.$this->targetDate;
        } catch (RepertoireCopyBlockedException|InvalidScreeningException $e) {
            $this->report = null;
            $this->previewFor = null;
            $this->problem = $e->getMessage();
        }
    }

    public function copy(RepertoireCopyService $copies): void
    {
        $cinema = $this->cinema();
        $this->validateDates();
        $this->problem = null;

        if ($this->previewFor !== $this->sourceDate.'>'.$this->targetDate) {
            $this->problem = 'Najpierw zrób podgląd dla wybranych dni.';

            return;
        }

        try {
            $report = $copies->copy($cinema, $this->sourceDate, $this->targetDate);
        } catch (RepertoireCopyBlockedException $e) {
            // Stan zmienił się od podglądu: pokazujemy świeży raport, nic nie zapisano.
            $this->report = $e->items === [] ? null : ['created' => 0, 'existing' => 0, 'items' => $e->items];
            $this->problem = $e->getMessage();

            return;
        } catch (InvalidScreeningException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        session()->flash('status', "Skopiowano repertuar: nowe seanse {$report['created']}, już istniejące {$report['existing']}.");
        $this->redirectRoute('admin.cinemas.screenings.index', ['cinema' => $cinema->slug, 'od' => $this->targetDate]);
    }

    public function render(): View
    {
        $cinema = $this->cinema();

        return view('livewire.admin.screenings.copy', [
            'cinema' => $cinema,
            'canCopy' => $this->report !== null
                && $this->previewFor === $this->sourceDate.'>'.$this->targetDate
                && ! in_array('problem', array_column($this->report['items'], 'status'), true)
                && $this->report['created'] > 0,
        ])->title('Kopiowanie repertuaru: '.$cinema->name);
    }

    private function cinema(): Cinema
    {
        $cinema = Cinema::query()->findOrFail($this->cinemaId);
        $this->authorize('create', Screening::class);

        return $cinema;
    }

    private function validateDates(): void
    {
        $this->validate([
            'sourceDate' => ['required', 'date_format:Y-m-d'],
            'targetDate' => ['required', 'date_format:Y-m-d', 'different:sourceDate'],
        ], [
            'targetDate.different' => 'Dzień docelowy musi być inny niż źródłowy.',
        ], [
            'sourceDate' => 'dzień źródłowy',
            'targetDate' => 'dzień docelowy',
        ]);
    }
}
