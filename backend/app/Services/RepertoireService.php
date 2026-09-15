<?php

namespace App\Services;

use App\Enums\ScreeningStatus;
use App\Enums\TicketStatus;
use App\Models\Cinema;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Odczyt repertuaru: kina, dni z seansami, seanse dnia.
 *
 * JEDYNE miejsce w aplikacji, przez które czyta się repertuar.
 * W Etapie 7 dołożymy tu cache w Redisie z inwalidacją przy zmianach
 * admina — zmienią się wnętrza metod, kontrolery i testy zostaną
 * nietknięte. Dlatego serwis powstaje już teraz.
 */
class RepertoireService
{
    /** Ile dni repertuaru pokazujemy do przodu (zadanie sugeruje 7-14). */
    private const HORIZON_DAYS = 14;

    /** Domyślna wielkość strony repertuaru dnia. */
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Czynne kina pogrupowane po miastach — pierwszy ekran ścieżki zakupowej.
     *
     * @return Collection<int, array{city: string, cinemas: EloquentCollection}>
     */
    public function cinemasByCity(): Collection
    {
        return Cinema::query()
            ->active()
            ->orderBy('city')
            ->orderBy('name')
            ->get()
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
     * Grupowanie robi PostgreSQL przez AT TIME ZONE, a nie PHP. Powód:
     * starts_at jest typu timestamptz, więc "dzień" zależy od strefy kina.
     * Seans o 00:30 czasu warszawskiego to w UTC poprzednia doba —
     * grupowanie po surowej dacie UTC wrzuciłoby go do złego dnia.
     *
     * @return Collection<int, array{date: string, screenings_count: int}>
     */
    public function availableDates(Cinema $cinema): Collection
    {
        return Screening::query()
            ->bookable()
            ->whereRelation('hall', 'cinema_id', $cinema->id)
            ->where('starts_at', '>', CarbonImmutable::now())
            ->where('starts_at', '<', CarbonImmutable::now()->addDays(self::HORIZON_DAYS))
            ->selectRaw(
                '(starts_at AT TIME ZONE ?)::date as day, count(*) as screenings_count',
                [$cinema->timezone],
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn (Screening $row): array => [
                'date' => (string) $row->day,
                'screenings_count' => (int) $row->screenings_count,
            ]);
    }

    /**
     * Seanse kina w danym dniu, stronicowane.
     *
     * Trzy liczniki w jednym zapytaniu zamiast pętli po seansach:
     *   active_seats_count  - ile miejsc ma sala (bez wyłączonych)
     *   sold_seats_count    - ile biletów sprzedano (bez anulowanych)
     *   held_seats_count    - ile miejsc trzymają aktywne blokady
     *
     * Wyprzedany = sold + held >= active_seats. Blokady liczą się jako
     * zajęte, bo gdyby nie liczyły, klient wchodziłby na plan sali
     * seansu, na którym nie ma już czego kupić.
     *
     * hall.cinema ładujemy dla strefy czasowej: to JEDNO dodatkowe
     * zapytanie na całą stronę, bo Eloquent pobiera kina hurtem.
     *
     * @param  string  $date  data w formacie Y-m-d, w strefie czasowej kina
     */
    public function screeningsForDay(
        Cinema $cinema,
        string $date,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): LengthAwarePaginator {
        $dayStart = CarbonImmutable::parse($date, $cinema->timezone)->startOfDay();
        $dayEnd = $dayStart->addDay();

        return Screening::query()
            ->whereRelation('hall', 'cinema_id', $cinema->id)
            // Odwołane i zakończone seanse nie pojawiają się w repertuarze.
            // Seanse, które już się zaczęły, ZOSTAJĄ — frontend ma je
            // wyszarzyć, a nie ukryć, żeby lista dnia była kompletna.
            ->where('status', ScreeningStatus::Scheduled->value)
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd)
            ->with([
                'movie',
                'hall' => fn ($query) => $query
                    ->with('cinema')
                    ->withCount([
                        'seats as active_seats_count' => fn (Builder $seats) => $seats
                            ->where('is_active', true),
                    ]),
            ])
            ->withCount([
                'tickets as sold_seats_count' => fn (Builder $query) => $query
                    ->where('status', '!=', TicketStatus::Cancelled->value),
                'seatLocks as held_seats_count' => fn (Builder $query) => $query
                    ->whereNull('released_at')
                    ->where('expires_at', '>', CarbonImmutable::now()),
            ])
            ->orderBy('starts_at')
            ->paginate($perPage)
            ->withQueryString();
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
}
