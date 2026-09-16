<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidScreeningException;
use App\Exceptions\ScreeningConflictException;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\PriceCategory;
use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Services\SeatStateRecorder;
use App\Support\CatalogCache;
use App\Support\ScreeningSlot;
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Planowanie seansów w panelu (Etap 7, blok G): dodawanie, zmiana, odwołanie.
 *
 * KOLIZJE — trzy warstwy, jedna definicja (ScreeningSlot::overlaps, przedziały [start, koniec_sprzątania)):
 * 1. blokada wiersza SALI (FOR UPDATE) szereguje wszystkie zmiany repertuaru tej sali
 *    i zmiany jej układu (HallLayoutService bierze tę samą blokadę);
 * 2. pod blokadą zapytanie o kolidujące seanse -> 409 z listą, co koliduje;
 * 3. constraint EXCLUDE screenings_no_overlap w bazie -> SQLSTATE 23P01 tłumaczony
 *    na ten sam wyjątek, gdy wiersz wstawił ktoś, kto ominął serwis.
 *
 * SPRZEDAŻ A ZMIANY: seans z aktywną blokadą miejsca, rezerwacją pending/paid albo
 * ważnym biletem jest zamrożony (cena, godzina, sala, film). Sprawdzenie jest
 * wiarygodne dzięki FOR UPDATE na wierszu seansu: INSERT do seat_locks, bookings
 * i tickets sprawdza klucz obcy przez FOR KEY SHARE na tym samym wierszu, więc
 * trwająca sprzedaż i zmiana w panelu czekają na siebie nawzajem (sprawdzone
 * empirycznie w bloku G). Zwykły UPDATE bierze słabsze FOR NO KEY UPDATE, które
 * tego NIE daje — dlatego lockForUpdate() jest jawne.
 *
 * KOLEJNOŚĆ BLOKAD (bez zakleszczeń): sale rosnąco po id -> kino (FOR SHARE)
 * -> film (FOR SHARE) -> seans. Kino i film pod FOR SHARE: równoległe wyłączenie
 * kina albo zmiana długości filmu czekają, aż nowy seans będzie widoczny
 * w ich liczniku nadchodzących seansów.
 */
final class ScreeningAdminService
{
    /** Górna granica ceny biletu w groszach (1000 zł) — literówka w cenie to realna strata. */
    public const MAX_PRICE = 100_000;

    public function __construct(
        private readonly CatalogCache $catalog,
        private readonly SeatStateRecorder $seatStates,
    ) {}

    /**
     * @param  array{hall_id: int, movie_id: int, date: string, time: string, projection_type: ProjectionType, language_version: LanguageVersion, prices: array<int, int>}  $data
     *
     * @throws InvalidScreeningException|ScreeningConflictException
     */
    public function create(array $data): Screening
    {
        return $this->translatingOverlap(function () use ($data): Screening {
            return DB::transaction(function () use ($data): Screening {
                $hall = $this->lockHalls([(int) $data['hall_id']])[(int) $data['hall_id']];
                [$cinema, $movie, $slot] = $this->validated($hall, $data, null);

                $screening = new Screening;
                $screening->fill([
                    'hall_id' => $hall->id,
                    'movie_id' => $movie->id,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'slot_ends_at' => $slot->slotEndsAt,
                    'projection_type' => $data['projection_type'],
                    'language_version' => $data['language_version'],
                    'status' => ScreeningStatus::Scheduled,
                ]);
                $screening->save();

                $this->storePrices($screening, $data['prices']);
                $this->catalog->bump(CatalogCache::cinema($cinema->id));

                return $screening;
            });
        });
    }

    /**
     * @param  array{hall_id: int, movie_id: int, date: string, time: string, projection_type: ProjectionType, language_version: LanguageVersion, prices: array<int, int>}  $data
     *
     * @throws InvalidScreeningException|ScreeningConflictException|StructureChangeBlockedException
     */
    public function update(Screening $screening, array $data): Screening
    {
        return $this->translatingOverlap(function () use ($screening, $data): Screening {
            return DB::transaction(function () use ($screening, $data): Screening {
                // Sale przed seansem (kolejność blokad). Salę seansu czytamy bez blokady,
                // a pod blokadą sprawdzamy, czy nikt jej w międzyczasie nie zmienił.
                $knownHallId = (int) Screening::query()->whereKey($screening->id)->value('hall_id');
                $halls = $this->lockHalls([$knownHallId, (int) $data['hall_id']]);
                $fresh = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

                if ($fresh->hall_id !== $knownHallId) {
                    throw StructureChangeBlockedException::screeningStale();
                }

                $this->assertEditable($fresh);
                $this->assertNoSales($fresh);

                $hall = $halls[(int) $data['hall_id']];

                if ($hall->cinema_id !== $halls[$knownHallId]->cinema_id) {
                    throw InvalidScreeningException::hallFromOtherCinema();
                }

                [$cinema, $movie, $slot] = $this->validated($hall, $data, $fresh->id);

                $fresh->fill([
                    'hall_id' => $hall->id,
                    'movie_id' => $movie->id,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'slot_ends_at' => $slot->slotEndsAt,
                    'projection_type' => $data['projection_type'],
                    'language_version' => $data['language_version'],
                ]);
                $fresh->save();

                // Seans bez sprzedaży: cennik można wymienić w całości.
                $fresh->prices()->delete();
                $this->storePrices($fresh, $data['prices']);

                // Otwarte plany sali pobierają stan od nowa (ceny, sala) — po COMMIT.
                $this->seatStates->recordLayoutChange($fresh->id);
                $this->catalog->bump(CatalogCache::cinema($cinema->id));

                return $fresh->load('prices');
            });
        });
    }

    /**
     * Odwołanie seansu bez rezerwacji. Termin w sali się zwalnia (constraint
     * pomija odwołane seanse), a wiersz zostaje dla historii.
     *
     * Aktywne blokady miejsc nie przeszkadzają: checkout sprawdza, czy seans
     * jest w sprzedaży, a blokady wygasną same. Rezerwacje pending/paid tak —
     * je najpierw anuluje administrator (z powodem i zwrotem pieniędzy, blok K).
     *
     * @throws StructureChangeBlockedException
     */
    public function cancel(Screening $screening): Screening
    {
        return DB::transaction(function () use ($screening): Screening {
            $fresh = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($fresh);

            $bookings = Booking::query()
                ->where('screening_id', $fresh->id)
                ->whereIn('status', [BookingStatus::Pending, BookingStatus::Paid])
                ->count();

            if ($bookings > 0) {
                throw StructureChangeBlockedException::screeningHasBookings($bookings);
            }

            $fresh->status = ScreeningStatus::Cancelled;
            $fresh->save();

            $this->seatStates->recordLayoutChange($fresh->id);
            $this->catalog->bump(CatalogCache::cinema((int) Hall::query()->whereKey($fresh->hall_id)->value('cinema_id')));

            return $fresh;
        });
    }

    /**
     * Sprzedaż, która zamraża seans. Wołać pod FOR UPDATE na wierszu seansu.
     *
     * @return array{locks: int, bookings: int, tickets: int}
     */
    public function salesActivity(Screening $screening): array
    {
        return [
            'locks' => SeatLock::query()
                ->where('screening_id', $screening->id)
                ->whereNull('released_at')
                ->where('expires_at', '>', CarbonImmutable::now())
                ->count(),
            'bookings' => Booking::query()
                ->where('screening_id', $screening->id)
                ->whereIn('status', [BookingStatus::Pending, BookingStatus::Paid])
                ->count(),
            'tickets' => Ticket::query()
                ->where('screening_id', $screening->id)
                ->where('status', '!=', TicketStatus::Cancelled)
                ->count(),
        ];
    }

    /**
     * Seanse sali nachodzące na przedział — ten sam warunek co constraint EXCLUDE.
     *
     * @return list<array{id: int, movie: string, starts_at: string, slot_ends_at: string}>
     */
    public function conflicts(int $hallId, ScreeningSlot $slot, ?int $exceptScreeningId, string $timezone): array
    {
        return Screening::query()
            ->with('movie:id,title')
            ->where('hall_id', $hallId)
            ->where('status', '!=', ScreeningStatus::Cancelled)
            ->where('starts_at', '<', $slot->slotEndsAt)
            ->where('slot_ends_at', '>', $slot->startsAt)
            ->when($exceptScreeningId !== null, fn ($query) => $query->whereKeyNot($exceptScreeningId))
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (Screening $other): array => [
                'id' => $other->id,
                'movie' => $other->movie->title,
                'starts_at' => $other->starts_at->setTimezone($timezone)->format('Y-m-d H:i'),
                'slot_ends_at' => $other->slot_ends_at->setTimezone($timezone)->format('H:i'),
            ])
            ->all();
    }

    /**
     * Wspólna walidacja dodawania i zmiany. Wymaga zablokowanej sali.
     *
     * @param  array{movie_id: int, date: string, time: string, projection_type: ProjectionType, prices: array<int, int>}  $data
     * @return array{0: Cinema, 1: Movie, 2: ScreeningSlot}
     */
    private function validated(Hall $hall, array $data, ?int $exceptScreeningId): array
    {
        $cinema = Cinema::query()->whereKey($hall->cinema_id)->sharedLock()->firstOrFail();

        if (! $hall->is_active || ! $cinema->is_active) {
            throw InvalidScreeningException::hallInactive();
        }

        $movie = Movie::query()->whereKey((int) $data['movie_id'])->sharedLock()->firstOrFail();

        if (! $movie->is_active) {
            throw InvalidScreeningException::movieInactive();
        }

        if (! $hall->supports($data['projection_type'])) {
            throw InvalidScreeningException::projectionUnsupported($data['projection_type']->value, $hall->name);
        }

        $timeline = ScreeningTimeline::fromConfig();
        $startsAt = $timeline->localStart($data['date'], $data['time'], $cinema->timezone);

        if ($startsAt <= CarbonImmutable::now()) {
            throw InvalidScreeningException::inPast();
        }

        $this->assertPricesCoverHall($hall, $data['prices']);

        $slot = $timeline->slot($startsAt, $movie->duration_minutes);
        $conflicts = $this->conflicts($hall->id, $slot, $exceptScreeningId, $cinema->timezone);

        if ($conflicts !== []) {
            throw new ScreeningConflictException($conflicts);
        }

        return [$cinema, $movie, $slot];
    }

    /** @param array<int, int> $prices price_category_id => grosze */
    private function assertPricesCoverHall(Hall $hall, array $prices): void
    {
        foreach ($prices as $categoryId => $price) {
            if (! is_int($price) || $price < 0 || $price > self::MAX_PRICE) {
                throw InvalidScreeningException::priceOutOfRange(self::MAX_PRICE);
            }
        }

        $known = PriceCategory::query()->whereIn('id', array_keys($prices))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_keys($prices) as $categoryId) {
            if (! in_array((int) $categoryId, $known, true)) {
                throw InvalidScreeningException::unknownPriceCategory((int) $categoryId);
            }
        }

        $needed = Seat::query()
            ->where('hall_id', $hall->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('price_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($needed === []) {
            throw InvalidScreeningException::hallWithoutSeats();
        }

        $missing = array_values(array_diff($needed, array_map('intval', array_keys($prices))));

        if ($missing !== []) {
            throw InvalidScreeningException::pricesMissing(
                PriceCategory::query()->whereIn('id', $missing)->ordered()->pluck('name')->all(),
            );
        }
    }

    /** @param array<int, int> $prices */
    private function storePrices(Screening $screening, array $prices): void
    {
        $now = CarbonImmutable::now();

        ScreeningPrice::query()->insert(array_map(
            fn (int $categoryId, int $price): array => [
                'screening_id' => $screening->id,
                'price_category_id' => $categoryId,
                'price' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_map('intval', array_keys($prices)),
            array_values($prices),
        ));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Hall> zablokowane sale, rosnąco po id
     */
    private function lockHalls(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        $halls = Hall::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($halls->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(Hall::class, $ids);
        }

        return $halls->all();
    }

    private function assertEditable(Screening $screening): void
    {
        if ($screening->status !== ScreeningStatus::Scheduled || $screening->starts_at <= CarbonImmutable::now()) {
            throw StructureChangeBlockedException::screeningNotEditable();
        }
    }

    private function assertNoSales(Screening $screening): void
    {
        $activity = $this->salesActivity($screening);

        if (array_sum($activity) > 0) {
            throw StructureChangeBlockedException::screeningHasSales($activity);
        }
    }

    /**
     * Naruszenie screenings_no_overlap (SQLSTATE 23P01) -> ScreeningConflictException.
     * Łapiemy POZA transakcją: po błędzie PostgreSQL odrzuca każde kolejne zapytanie
     * w tej transakcji. Listy kolizji tu nie ma — panel po błędzie pokazuje świeżą siatkę.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function translatingOverlap(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23P01' || ! str_contains($e->getMessage(), 'screenings_no_overlap')) {
                throw $e;
            }

            throw new ScreeningConflictException([], detectedByDatabase: true);
        }
    }
}
