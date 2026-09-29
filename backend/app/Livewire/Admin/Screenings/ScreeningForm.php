<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Screenings;

use App\Enums\BookingStatus;
use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Exceptions\BookingCancellationException;
use App\Exceptions\InvalidScreeningException;
use App\Exceptions\ScreeningConflictException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\Seat;
use App\Services\Admin\ScreeningAdminService;
use App\Services\Admin\ScreeningCancellationService;
use App\Support\Money;
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Dodawanie, zmiana i odwołanie seansu z cennikiem (Etap 7, blok G2).
 *
 * Komponent zbiera dane i pokazuje podpowiedzi (koniec filmu, zwolnienie sali),
 * ale decyzje — kolizje, cennik, sprzedaż — podejmuje ScreeningAdminService
 * pod blokadami. Godzina w formularzu jest w strefie kina.
 *
 * CENY wpisuje się w złotych ("25" albo "25,50"). Na grosze zamieniamy tekst,
 * a nie liczbę zmiennoprzecinkową: 19,99 * 100 w float to 1998,999…
 */
final class ScreeningForm extends Component
{
    #[Locked]
    public int $cinemaId;

    #[Locked]
    public ?int $screeningId = null;

    public string $hallId = '';

    public string $movieId = '';

    public string $date = '';

    public string $time = '18:00';

    public string $projectionType = '';

    public string $languageVersion = 'subtitles';

    /** @var array<int|string, string> price_category_id => cena w złotych (tekst) */
    public array $prices = [];

    public ?string $problem = null;

    /** Potwierdzenie odwołania seansu RAZEM z rezerwacjami (Etap 9, blok L). */
    public bool $confirmingMassCancel = false;

    /** Powód anulowania — ten sam, który dostaną wszystkie rezerwacje seansu. */
    public string $cancelReason = '';

    /** Komunikat z raportu: ile anulowano, ile zwrotów czeka na rozliczenie. */
    public ?string $cancelReport = null;

    public function mount(?Cinema $cinema = null, ?Screening $screening = null): void
    {
        if ($screening !== null && $screening->exists) {
            $this->authorize('update', $screening);
            $screening->load(['hall.cinema', 'prices']);
            $tz = $screening->hall->cinema->timezone;

            $this->cinemaId = $screening->hall->cinema_id;
            $this->screeningId = $screening->id;
            $this->hallId = (string) $screening->hall_id;
            $this->movieId = (string) $screening->movie_id;
            $this->date = $screening->starts_at->setTimezone($tz)->toDateString();
            $this->time = $screening->starts_at->setTimezone($tz)->format('H:i');
            $this->projectionType = $screening->projection_type->value;
            $this->languageVersion = $screening->language_version->value;
            $this->prices = $screening->prices->mapWithKeys(fn ($price): array => [$price->price_category_id => $this->zloty($price->price)])->all();

            return;
        }

        $this->authorize('create', Screening::class);
        abort_if($cinema === null || ! $cinema->exists, 404);

        $this->cinemaId = $cinema->id;
        $hall = $this->halls()->firstWhere('id', (int) request()->query('sala'))
            ?? $this->halls()->firstWhere('is_active', true);
        $this->hallId = $hall ? (string) $hall->id : '';

        $date = (string) request()->query('data');
        $this->date = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
            ? $date
            : CarbonImmutable::now($cinema->timezone)->addDay()->toDateString();

        $this->updatedHallId();
    }

    /** Zmiana sali: typ projekcji z tej sali i wiersze cennika dla jej kategorii miejsc. */
    public function updatedHallId(): void
    {
        $hall = $this->selectedHall();

        if ($hall === null) {
            $this->prices = [];

            return;
        }

        if (! in_array($this->projectionType, $hall->projection_types, true)) {
            $this->projectionType = $hall->projection_types[0] ?? '';
        }

        // Podpowiedź cen: ostatni seans tej sali; wpisane już ceny zostają.
        $last = Screening::query()->where('hall_id', $hall->id)->latest('starts_at')->with('prices')->first();
        $prices = [];

        foreach ($this->categoriesFor($hall) as $category) {
            $prices[$category->id] = $this->prices[$category->id]
                ?? ($last?->priceFor($category->id) !== null ? $this->zloty($last->priceFor($category->id)) : '');
        }

        $this->prices = $prices;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'hallId' => ['required', 'integer', Rule::exists('halls', 'id')->where('cinema_id', $this->cinemaId)],
            'movieId' => ['required', 'integer', Rule::exists('movies', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'projectionType' => ['required', Rule::enum(ProjectionType::class)],
            'languageVersion' => ['required', Rule::enum(LanguageVersion::class)],
            'prices' => ['array'],
            'prices.*' => ['required', 'regex:/\A\d{1,4}([.,]\d{1,2})?\z/'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'prices.*.required' => 'Podaj cenę.',
            'prices.*.regex' => 'Cena w złotych, np. 25 albo 25,50.',
            'date.date_format' => 'Data w formacie RRRR-MM-DD.',
            'time.date_format' => 'Godzina w formacie GG:MM.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'hallId' => 'sala',
            'movieId' => 'film',
            'date' => 'data',
            'time' => 'godzina',
            'projectionType' => 'typ projekcji',
            'languageVersion' => 'wersja językowa',
        ];
    }

    public function save(ScreeningAdminService $service): void
    {
        $this->problem = null;
        $this->validate();

        $data = [
            'hall_id' => (int) $this->hallId,
            'movie_id' => (int) $this->movieId,
            'date' => $this->date,
            'time' => $this->time,
            'projection_type' => ProjectionType::from($this->projectionType),
            'language_version' => LanguageVersion::from($this->languageVersion),
            'prices' => array_map(fn (string $zloty): int => $this->grosze($zloty), $this->prices),
        ];

        try {
            if ($this->screeningId === null) {
                $this->authorize('create', Screening::class);
                $screening = $service->create($data);
                session()->flash('status', 'Dodano seans.');
            } else {
                $screening = Screening::query()->findOrFail($this->screeningId);
                $this->authorize('update', $screening);
                $service->update($screening, $data);
                session()->flash('status', 'Zapisano zmiany seansu.');
            }
        } catch (ScreeningConflictException $e) {
            $this->addError('time', $e->getMessage());

            return;
        } catch (InvalidScreeningException $e) {
            $this->addError(match ($e->field()) {
                'startsAt' => 'time',
                'prices' => 'prices',
                default => $e->field(),
            }, $e->getMessage());

            return;
        } catch (StructureChangeBlockedException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->redirectRoute('admin.cinemas.screenings.index', [
            'cinema' => Cinema::query()->whereKey($this->cinemaId)->value('slug'),
            'od' => $this->date,
        ]);
    }

    /**
     * Pokazuje potwierdzenie z LICZBAMI (decyzja 347).
     *
     * Osobny krok, a nie `wire:confirm`: przeglądarkowe „na pewno?" nie potrafi powiedzieć,
     * ilu klientów dostanie powiadomienie ani ile pieniędzy wróci, a to jedyna akcja w panelu,
     * która jednym kliknięciem anuluje cudze zakupy.
     */
    public function askMassCancel(): void
    {
        $this->problem = null;
        $this->cancelReport = null;
        $this->confirmingMassCancel = true;
    }

    public function dismissMassCancel(): void
    {
        $this->confirmingMassCancel = false;
        $this->reset('cancelReason');
    }

    /** Odwołanie seansu razem z jego rezerwacjami. */
    public function cancelScreeningWithBookings(ScreeningCancellationService $service): void
    {
        abort_if($this->screeningId === null, 404);

        $screening = Screening::query()->findOrFail($this->screeningId);
        $this->authorize('update', $screening);
        $this->problem = null;

        try {
            $report = $service->cancelWithBookings($screening, auth()->user(), $this->cancelReason);
        } catch (BookingCancellationException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        } catch (StructureChangeBlockedException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        if (! $report->screeningCancelled) {
            // Część rezerwacji została — seansu NIE odwołujemy (decyzja 346),
            // bo ktoś zostałby z ważnym biletem na seans, którego nie ma.
            $this->cancelReport = $report->message();
            $this->confirmingMassCancel = false;

            return;
        }

        session()->flash('status', $report->message());
        $this->redirectRoute('admin.cinemas.screenings.index', [
            'cinema' => Cinema::query()->whereKey($this->cinemaId)->value('slug'),
            'od' => $this->date,
        ]);
    }

    /** Suma rezerwacji OPŁACONYCH — tyle realnie wróci do klientów. */
    private function moneyAtStake(Screening $screening): string
    {
        $amount = (int) Booking::query()
            ->where('screening_id', $screening->id)
            ->where('status', BookingStatus::Paid)
            ->sum('total_amount');

        return Money::minor($amount)->toArray()['formatted'];
    }

    public function render(): View
    {
        $service = app(ScreeningAdminService::class);
        $cinema = Cinema::query()->findOrFail($this->cinemaId);
        $hall = $this->selectedHall();
        $screening = $this->screeningId === null ? null : Screening::query()->findOrFail($this->screeningId);

        return view('livewire.admin.screenings.form', [
            'cinema' => $cinema,
            'screening' => $screening,
            'halls' => $this->halls(),
            'movies' => Movie::query()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $this->movieId))
                ->orderBy('title')
                ->get(['id', 'title', 'duration_minutes']),
            'projectionTypes' => $hall === null ? [] : array_map(fn (string $type): ProjectionType => ProjectionType::from($type), $hall->projection_types),
            'languages' => LanguageVersion::cases(),
            'categories' => $hall === null ? collect() : $this->categoriesFor($hall),
            'preview' => $this->preview($cinema),
            'lockedReason' => $screening === null ? null : $this->lockedReason($screening, $service),
            'canCancel' => $screening !== null && $screening->status === ScreeningStatus::Scheduled && $screening->starts_at->isFuture(),
            // Liczby do potwierdzenia (decyzja 347): ile rezerwacji i ile pieniędzy.
            // Liczymy je dopiero przy pokazywaniu potwierdzenia, żeby zwykła edycja
            // seansu nie płaciła za trzy zapytania, których nikt nie ogląda.
            'sales' => $screening === null || ! $this->confirmingMassCancel ? null : $service->salesActivity($screening),
            'salesMoney' => $screening === null || ! $this->confirmingMassCancel ? null : $this->moneyAtStake($screening),
        ])->title($this->screeningId === null ? 'Nowy seans' : 'Edycja seansu');
    }

    /** @return Collection<int, Hall> */
    private function halls(): Collection
    {
        return Hall::query()->where('cinema_id', $this->cinemaId)->orderBy('name')->get(['id', 'name', 'is_active', 'projection_types', 'cinema_id']);
    }

    private function selectedHall(): ?Hall
    {
        return $this->halls()->firstWhere('id', (int) $this->hallId);
    }

    /** @return Collection<int, PriceCategory> kategorie aktywnych miejsc sali */
    private function categoriesFor(Hall $hall): Collection
    {
        $ids = Seat::query()->where('hall_id', $hall->id)->where('is_active', true)->distinct()->pluck('price_category_id');

        return PriceCategory::query()->whereIn('id', $ids)->ordered()->get(['id', 'name', 'color']);
    }

    /** Podpowiedź: koniec filmu i zwolnienie sali w czasie lokalnym (null, gdy dane niepełne). */
    private function preview(Cinema $cinema): ?string
    {
        $movie = Movie::query()->find((int) $this->movieId);

        if ($movie === null) {
            return null;
        }

        try {
            $timeline = ScreeningTimeline::fromConfig();
            $slot = $timeline->slot($timeline->localStart($this->date, $this->time, $cinema->timezone), $movie->duration_minutes);
        } catch (Throwable) {
            return null;
        }

        return sprintf(
            'Film %d min + %d min reklam: koniec %s, sala wolna po sprzątaniu o %s.',
            $movie->duration_minutes,
            $timeline->adsMinutes,
            $slot->endsAt->setTimezone($cinema->timezone)->format('H:i'),
            $slot->slotEndsAt->setTimezone($cinema->timezone)->format('H:i'),
        );
    }

    private function lockedReason(Screening $screening, ScreeningAdminService $service): ?string
    {
        if ($screening->status !== ScreeningStatus::Scheduled || ! $screening->starts_at->isFuture()) {
            return 'Seans jest odwołany, zakończony albo już się zaczął — tylko do podglądu.';
        }

        $activity = $service->salesActivity($screening);

        return array_sum($activity) > 0
            ? "Seans ma sprzedaż (blokady: {$activity['locks']}, rezerwacje: {$activity['bookings']}, bilety: {$activity['tickets']}) — zmiany są zablokowane."
            : null;
    }

    private function zloty(int $grosze): string
    {
        return intdiv($grosze, 100).','.str_pad((string) ($grosze % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "25" -> 2500, "25,5" -> 2550, "25.05" -> 2505 (po walidacji regex). */
    private function grosze(string $zloty): int
    {
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', trim($zloty)), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
