<?php

namespace App\Services;

use App\Enums\ScreeningStatus;
use App\Enums\TicketStatus;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as PagePaginator;
use Illuminate\Support\Collection;

/**
 * Odczyt repertuaru: kina, dni z seansami, seanse dnia.
 *
 * JEDYNE miejsce w aplikacji, przez które czyta się repertuar.
 * W Etapie 7 doszedł tu cache w Redisie z inwalidacją przy zmianach
 * admina — zmieniły się wnętrza metod, a kontrolery, zasoby API
 * i ich testy zostały nietknięte.
 *
 * CO JEST W CACHE (CatalogCache): wyłącznie TABLICE atrybutów z bazy —
 * nigdy modele (serializable_classes = false, pułapka BB). Przy odczycie
 * modele odtwarzamy przez newFromBuilder() i setRelation(), więc zasoby
 * API widzą to samo, co przy zwykłym zapytaniu.
 *
 * CZEGO NIE MA W CACHE: danych, które zmieniają się przy każdej sprzedaży
 * (sprzedane i zablokowane miejsca) i wszystkiego, co zależy od "teraz".
 * Te liczymy przy odczycie — inaczej premiera unieważniałaby cache co
 * sekundę, a seanse "rozpoczęte" wisiałyby w cache do końca TTL.
 */
class RepertoireService
{
    /** Ile dni repertuaru pokazujemy do przodu (zadanie sugeruje 7-14). */
    private const HORIZON_DAYS = 14;

    /** Domyślna wielkość strony repertuaru dnia. */
    public const DEFAULT_PER_PAGE = 50;

    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * Czynne kina pogrupowane po miastach — pierwszy ekran ścieżki zakupowej.
     *
     * W cache atrybuty kin (generacja CINEMAS: każda zmiana kina w panelu).
     *
     * @return Collection<int, array{city: string, cinemas: EloquentCollection}>
     */
    public function cinemasByCity(): Collection
    {
        $rows = $this->catalog->remember(
            'cinemas:active',
            [CatalogCache::CINEMAS],
            fn (): array => Cinema::query()
                ->active()
                ->orderBy('city')
                ->orderBy('name')
                ->get()
                ->map(fn (Cinema $cinema): array => $cinema->getAttributes())
                ->all(),
        );

        return Cinema::hydrate($rows)
            ->groupBy('city')
            ->map(fn (EloquentCollection $cinemas, string $city): array => [
                'city' => $city,
                'cinemas' => $cinemas,
            ])
            ->values();
    }

    /**
     * Dni, w których kino ma jeszcze dostępne seanse — dane dla kalendarza.
     *
     * W cache: momenty rozpoczęcia seansów w sprzedaży (znaczniki UNIX) w oknie
     * HORIZON_DAYS + TTL cache. Okno w cache zawsze pokrywa okno zapytania
     * ("teraz" do "teraz + 14 dni"), więc upływ czasu nie psuje wyniku.
     *
     * Filtr "> teraz" i grupowanie po dniu W STREFIE KINA robimy przy odczycie.
     * Seans o 00:30 czasu warszawskiego to w UTC poprzednia doba — dlatego
     * dzień liczymy z momentu przestawionego na strefę kina, a nie z daty UTC.
     *
     * @return Collection<int, array{date: string, screenings_count: int}>
     */
    public function availableDates(Cinema $cinema): Collection
    {
        $starts = $this->catalog->remember(
            'repertoire:starts:c'.$cinema->id,
            [CatalogCache::cinema($cinema->id)],
            fn (): array => Screening::query()
                ->bookable()
                ->whereRelation('hall', 'cinema_id', $cinema->id)
                ->where('starts_at', '<', CarbonImmutable::now()
                    ->addDays(self::HORIZON_DAYS)
                    ->addSeconds($this->catalog->ttlSeconds()))
                ->orderBy('starts_at')
                ->pluck('starts_at')
                ->map(fn (CarbonInterface $startsAt): int => $startsAt->getTimestamp())
                ->all(),
        );

        $now = CarbonImmutable::now();
        $from = $now->getTimestamp();
        $until = $now->addDays(self::HORIZON_DAYS)->getTimestamp();

        return collect($starts)
            ->filter(fn (int $timestamp): bool => $timestamp > $from && $timestamp < $until)
            ->countBy(fn (int $timestamp): string => CarbonImmutable::createFromTimestamp($timestamp, $cinema->timezone)->toDateString())
            ->map(fn (int $count, string $date): array => [
                'date' => $date,
                'screenings_count' => $count,
            ])
            ->values();
    }

    /**
     * Seanse kina w danym dniu, stronicowane.
     *
     * W cache: cały dzień jako tablice (seans, film, sala z liczbą aktywnych
     * miejsc, kino). Generacje: kino (seanse, sale, układy) i filmy (tytuł,
     * plakat, czas trwania). Dzień ma kilkadziesiąt seansów, więc stronicujemy
     * w PHP, a nie osobnym kluczem na każdą stronę.
     *
     * Przy odczycie, tylko dla seansów z bieżącej strony, dwa zapytania
     * agregujące: sprzedane bilety i aktywne blokady. Wyprzedany =
     * sold + held >= active_seats (ScreeningListItemResource). Blokady liczą
     * się jako zajęte, bo inaczej klient wchodziłby na plan sali seansu,
     * na którym nie ma już czego kupić.
     *
     * Seanse, które już się zaczęły, ZOSTAJĄ — frontend ma je wyszarzyć.
     * has_started liczy zasób API przy każdym odczycie, nie cache.
     *
     * @param  string  $date  data w formacie Y-m-d, w strefie czasowej kina
     */
    public function screeningsForDay(
        Cinema $cinema,
        string $date,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): LengthAwarePaginator {
        $rows = $this->catalog->remember(
            'repertoire:day:c'.$cinema->id.':'.$date,
            [CatalogCache::cinema($cinema->id), CatalogCache::MOVIES],
            fn (): array => $this->dayRows($cinema, $date),
        );

        $page = PagePaginator::resolveCurrentPage();
        $screenings = $this->hydrateDay(array_slice($rows, ($page - 1) * $perPage, $perPage));
        $this->attachLiveSeatCounts($screenings);

        return (new PagePaginator($screenings, count($rows), $perPage, $page, [
            'path' => PagePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->withQueryString();
    }

    /** Pojedynczy seans z kompletem danych do ekranu szczegółów. */
    public function screeningDetails(Screening $screening): Screening
    {
        return $screening->loadMissing([
            'movie',
            'hall.cinema',
            'prices.priceCategory',
        ]);
    }

    /**
     * Zapytanie do bazy dla repertuaru dnia — wynik trafia do cache.
     *
     * Odwołane i zakończone seanse nie pojawiają się w repertuarze. Sortowanie
     * także po id: dwa seanse o tej samej godzinie w różnych salach muszą mieć
     * stałą kolejność, inaczej granica stron mogłaby "przeskakiwać".
     *
     * @return list<array{screening: array<string, mixed>, movie: array<string, mixed>, hall: array<string, mixed>, cinema: array<string, mixed>}>
     */
    private function dayRows(Cinema $cinema, string $date): array
    {
        $dayStart = CarbonImmutable::parse($date, $cinema->timezone)->startOfDay();

        return Screening::query()
            ->whereRelation('hall', 'cinema_id', $cinema->id)
            ->where('status', ScreeningStatus::Scheduled->value)
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayStart->addDay())
            ->with([
                'movie',
                'hall' => fn ($query) => $query
                    ->with('cinema')
                    ->withCount([
                        'seats as active_seats_count' => fn (Builder $seats) => $seats
                            ->where('is_active', true),
                    ]),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Screening $screening): array => [
                'screening' => $screening->getAttributes(),
                'movie' => $screening->movie->getAttributes(),
                'hall' => $screening->hall->getAttributes(),
                'cinema' => $screening->hall->cinema->getAttributes(),
            ])
            ->all();
    }

    /**
     * Tablice z cache -> modele z relacjami, tak jak po eager loadingu.
     *
     * newFromBuilder() ustawia surowe atrybuty i exists = true, więc rzutowania
     * (daty, enumy, jsonb) działają jak po zwykłym zapytaniu. Kino, sala i film
     * są współdzielone między seansami, jak przy with() — bez duplikatów.
     * Relacje ustawione ręcznie nie wywołują preventLazyLoading.
     *
     * @param  list<array{screening: array<string, mixed>, movie: array<string, mixed>, hall: array<string, mixed>, cinema: array<string, mixed>}>  $rows
     * @return EloquentCollection<int, Screening>
     */
    private function hydrateDay(array $rows): EloquentCollection
    {
        $cinemas = [];
        $halls = [];
        $movies = [];
        $screenings = [];

        foreach ($rows as $row) {
            $cinema = $cinemas[$row['cinema']['id']] ??= (new Cinema)->newFromBuilder($row['cinema']);
            $hall = $halls[$row['hall']['id']] ??= (new Hall)->newFromBuilder($row['hall'])->setRelation('cinema', $cinema);
            $movie = $movies[$row['movie']['id']] ??= (new Movie)->newFromBuilder($row['movie']);

            $screenings[] = (new Screening)->newFromBuilder($row['screening'])
                ->setRelation('movie', $movie)
                ->setRelation('hall', $hall);
        }

        return new EloquentCollection($screenings);
    }

    /**
     * Sprzedane i zablokowane miejsca — zawsze na żywo, nigdy z cache.
     *
     * Dwa zapytania z GROUP BY dla całej strony zamiast pętli po seansach.
     * Warunki takie same jak dotychczasowe withCount: bilety bez anulowanych,
     * blokady niezwolnione i niewygasłe ("expires_at > teraz" musi być
     * w zapytaniu — predykat indeksu nie może zawierać now(), pułapka A).
     *
     * @param  EloquentCollection<int, Screening>  $screenings
     */
    private function attachLiveSeatCounts(EloquentCollection $screenings): void
    {
        if ($screenings->isEmpty()) {
            return;
        }

        $ids = $screenings->modelKeys();

        $sold = Ticket::query()
            ->whereIn('screening_id', $ids)
            ->where('status', '!=', TicketStatus::Cancelled->value)
            ->groupBy('screening_id')
            ->selectRaw('screening_id, count(*) as aggregate')
            ->pluck('aggregate', 'screening_id');

        $held = SeatLock::query()
            ->whereIn('screening_id', $ids)
            ->whereNull('released_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->groupBy('screening_id')
            ->selectRaw('screening_id, count(*) as aggregate')
            ->pluck('aggregate', 'screening_id');

        foreach ($screenings as $screening) {
            $screening->setAttribute('sold_seats_count', (int) ($sold[$screening->id] ?? 0));
            $screening->setAttribute('held_seats_count', (int) ($held[$screening->id] ?? 0));
        }
    }
}
