<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\InvalidSeatSelectionException;
use App\Exceptions\ScreeningNotBookableException;
use App\Exceptions\SeatLockLimitExceededException;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tymczasowe blokowanie miejsc na seansie.
 *
 * GWARANCJA POPRAWNOŚCI daje częściowy indeks UNIQUE w PostgreSQL:
 *
 *     CREATE UNIQUE INDEX seat_locks_active_unique
 *         ON seat_locks (screening_id, seat_id) WHERE released_at IS NULL;
 *
 * Przy dwóch równoczesnych INSERT-ach tej samej pary druga transakcja czeka na
 * rozstrzygnięcie pierwszej, po czym dostaje SQLSTATE 23505. Nie ma okna, w którym
 * obie mogłyby przejść — w przeciwieństwie do sprawdzenia "if (! exists) insert",
 * które jest klasycznym TOCTOU i przy 100 równoczesnych żądaniach po prostu nie działa.
 *
 * PUŁAPKA, KTÓRĄ TEN SERWIS OBSŁUGUJE:
 * predykat indeksu nie może zawierać "AND expires_at > now()", bo PostgreSQL wymaga
 * w predykacie funkcji IMMUTABLE, a now() jest STABLE. Skutek: blokada WYGASŁA, ale
 * niezwolniona, nadal zajmuje miejsce w indeksie. Dlatego lock() zwalnia wygasłe
 * blokady W TEJ SAMEJ TRANSAKCJI, tuż przed swoim INSERT-em. Scheduler (sweepExpired)
 * to higiena tabeli, a nie mechanizm poprawności — gdyby nie działał, system nadal
 * sprzedawałby prawidłowo.
 *
 * CZAS bierzemy z Carbon::now(), a nie z bazowego now(), żeby testy mogły przesuwać
 * zegar przez Carbon::setTestNow() i sprawdzać wygasanie TTL bez czekania 10 minut.
 */
class SeatLockService
{
    public function __construct(
        private readonly SeatStateRecorder $seatStates,
    ) {}

    /**
     * Zakłada blokady na wskazanych miejscach. Operacja jest all-or-nothing:
     * jeśli choć jedno miejsce jest zajęte, nie powstaje żadna blokada.
     *
     * @param  list<int>  $seatIds
     * @return Collection<int, SeatLock>  wszystkie aktywne blokady tej sesji na tych miejscach
     *
     * @throws InvalidSeatSelectionException|ScreeningNotBookableException
     * @throws SeatLockLimitExceededException|SeatsUnavailableException
     */
    public function lock(Screening $screening, array $seatIds, string $sessionId, ?int $userId = null): Collection
    {
        // Walidacja poza transakcją: jest tania i nie ma sensu trzymać przy niej locków.
        $seatIds = $this->normalizeSeatIds($seatIds);
        $this->assertScreeningIsBookable($screening);
        $this->assertSeatsBelongToScreening($screening, $seatIds);

        $ttl = (int) config('cinema.seat_lock.ttl', 600);

        try {
            return DB::transaction(function () use ($screening, $seatIds, $sessionId, $userId, $ttl) {
                $now = Carbon::now();

                // 1) Zwolnij wygasłe blokady na tych miejscach — inaczej zablokują nam indeks.
                $this->releaseExpiredLocks($screening, $seatIds, $now);

                // 2) IDEMPOTENCJA: miejsca, które ta sesja już trzyma, pomijamy przy wstawianiu.
                //    Podwójne kliknięcie i retry po timeoucie kończą się sukcesem, nie błędem.
                //    Świadomie NIE przedłużamy expires_at — inaczej klient trzymałby fotel
                //    w nieskończoność, pingując endpoint co minutę.
                $alreadyOwned = $this->ownedSeatIds($screening, $seatIds, $sessionId, $now);
                $toInsert = array_values(array_diff($seatIds, $alreadyOwned));

                $this->assertWithinLimit($screening, $sessionId, count($toInsert), $now);

                if ($toInsert !== []) {
                    // Jeden INSERT na wszystkie miejsca: konflikt na którymkolwiek wywraca
                    // całe zapytanie, a wraz z nim transakcję. O to chodzi — nie chcemy
                    // sprzedać 3 miejsc z 5 i posadzić rodziny osobno.
                    SeatLock::insert(array_map(fn (int $seatId): array => [
                        'screening_id' => $screening->id,
                        'seat_id' => $seatId,
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                        'booking_id' => null,
                        'expires_at' => $now->copy()->addSeconds($ttl),
                        'released_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $toInsert));
                }

                // 3) Bilety są w INNEJ tabeli, więc indeks blokad ich nie pilnuje.
                //    Sprawdzamy PO wstawieniu blokady: skoro INSERT przeszedł, to nikt nie
                //    trzyma aktywnej blokady tego miejsca, a bilet powstaje wyłącznie
                //    z aktywnej blokady — więc nikt nie jest właśnie w trakcie finalizacji.
                //    Zostaje tylko sprawdzić, czy miejsce nie zostało sprzedane wcześniej.
                $sold = $this->soldSeatIds($screening, $seatIds);

                if ($sold !== []) {
                    throw new SeatsUnavailableException($sold, $this->labelsFor($sold));
                }

                $locks = $this->activeLocksFor($screening, $sessionId, $seatIds);

                // 4) Wersja stanu miejsc (Etap 6) — OSTATNIA instrukcja transakcji.
                //    Stan zmieniły tylko nowo wstawione miejsca; retry własnych
                //    miejsc ($toInsert puste) nie podbija licznika.
                $this->seatStates->record($screening->id, [SeatStateRecorder::HELD => $toInsert]);

                return $locks;
            });
        } catch (UniqueConstraintViolationException) {
            // SQLSTATE 23505 — ktoś nas ubiegł. Transakcja jest już wycofana; dopiero teraz,
            // POZA nią, wolno odpytać bazę. W PostgreSQL transakcja po błędzie jest zatruta
            // (25P02) i nie wykonałaby żadnego kolejnego zapytania.
            $taken = $this->takenSeatIds($screening, $seatIds, $sessionId);

            // Nie ponawiamy próby: retry ukryłby kontencję, a klient i tak zobaczy
            // odświeżony plan sali i kliknie inne miejsce.
            throw new SeatsUnavailableException($taken, $this->labelsFor($taken));
        }
    }

    /**
     * Zwalnia wskazane miejsca. Idempotentne: zwolnienie czegoś, co już jest zwolnione,
     * nie jest błędem — zwracamy po prostu 0.
     *
     * @param  list<int>  $seatIds
     * @return int  liczba faktycznie zwolnionych blokad
     */
    public function release(Screening $screening, array $seatIds, string $sessionId): int
    {
        $seatIds = $this->normalizeSeatIds($seatIds);

        return $this->releaseLocks($screening, SeatLock::query()
            ->where('screening_id', $screening->id)
            ->whereIn('seat_id', $seatIds)
            ->where('session_id', $sessionId)   // nie da się zwolnić cudzej blokady
            ->whereNull('booking_id')           // blokada wpięta w rezerwację należy do płatności
            ->whereNull('released_at'));
    }

    /** Porzucenie sesji: zwalnia wszystko, co ta sesja trzyma na tym seansie. */
    public function releaseSession(Screening $screening, string $sessionId): int
    {
        return $this->releaseLocks($screening, SeatLock::query()
            ->where('screening_id', $screening->id)
            ->where('session_id', $sessionId)
            ->whereNull('booking_id')
            ->whereNull('released_at'));
    }

    /**
     * Aktywne blokady sesji: niezwolnione ORAZ niewygasłe.
     * Warunek expires_at musi być tutaj jawnie, bo indeks go nie zawiera.
     *
     * @param  list<int>|null  $seatIds
     * @return Collection<int, SeatLock>
     */
    public function activeLocksFor(Screening $screening, string $sessionId, ?array $seatIds = null): Collection
    {
        return SeatLock::query()
            ->where('screening_id', $screening->id)
            ->where('session_id', $sessionId)
            ->whereNull('released_at')
            ->where('expires_at', '>', Carbon::now())
            ->when($seatIds !== null, fn ($query) => $query->whereIn('seat_id', $seatIds))
            ->orderBy('seat_id')
            ->get();
    }

    /**
     * Higiena tabeli: oznacza wygasłe blokady jako zwolnione. Wywoływane przez scheduler.
     *
     * Dwa kroki (SELECT id, potem UPDATE ... WHERE id IN), bo PostgreSQL nie obsługuje
     * UPDATE ... LIMIT. Porcjujemy, żeby jeden przebieg nie zakładał locków na dziesiątkach
     * tysięcy wierszy i nie blokował sprzedaży.
     */
    public function sweepExpired(?int $limit = null): int
    {
        $limit ??= (int) config('cinema.seat_lock.sweep_batch', 500);
        $now = Carbon::now();

        $ids = SeatLock::query()
            ->whereNull('released_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $now): int {
            // FOR UPDATE: ktoś mógł zwolnić blokadę między SELECT-em a tą chwilą.
            // Wersja i zdarzenie mają opisywać wiersze zwolnione przez TĘ
            // transakcję, a nie wynik wcześniejszego SELECT-a (Etap 6).
            // Porcję WYBIERAMY po id (stronicowanie), ale BLOKUJEMY w kolejności
            // wspólnej dla całej aplikacji (blok J).
            $locks = SeatLock::query()
                ->whereIn('id', $ids)
                ->whereNull('released_at')
                ->inLockOrder()
                ->lockForUpdate()
                ->get(['id', 'screening_id', 'seat_id']);

            if ($locks->isEmpty()) {
                return 0;
            }

            $released = SeatLock::query()
                ->whereIn('id', $locks->pluck('id'))
                ->update(['released_at' => $now, 'updated_at' => $now]);

            // Porcja może objąć kilka seansów. Liczniki podbijamy rosnąco po
            // screening_id — stała kolejność, więc bez deadlocków (jak seat_id).
            foreach ($locks->groupBy('screening_id')->sortKeys() as $screeningId => $screeningLocks) {
                $this->seatStates->record((int) $screeningId, [
                    SeatStateRecorder::FREE => $screeningLocks->pluck('seat_id')->all(),
                ]);
            }

            return $released;
        });
    }

    /**
     * Zwalnia blokady wskazane zapytaniem i rejestruje zmianę stanu (Etap 6).
     *
     * Dwa kroki w jednej transakcji: SELECT ... FOR UPDATE, potem UPDATE po id.
     * Zablokowane wiersze nie zmienią się pod nami, więc lista miejsc jest
     * dokładnie tą, którą zwolniła ta transakcja. Sam UPDATE zwraca tylko
     * liczbę, a wersja i zdarzenie potrzebują identyfikatorów miejsc.
     * Kolejność blokad: SeatLock::inLockOrder() — wspólna dla całej aplikacji (blok J).
     *
     * @param  Builder<SeatLock>  $query
     * @return int  liczba faktycznie zwolnionych blokad
     */
    private function releaseLocks(Screening $screening, Builder $query): int
    {
        return DB::transaction(function () use ($screening, $query): int {
            $now = Carbon::now();

            $locks = $query->inLockOrder()->lockForUpdate()->get(['id', 'seat_id']);

            if ($locks->isEmpty()) {
                return 0;
            }

            $released = SeatLock::query()
                ->whereIn('id', $locks->pluck('id'))
                ->update(['released_at' => $now, 'updated_at' => $now]);

            $this->seatStates->record($screening->id, [
                SeatStateRecorder::FREE => $locks->pluck('seat_id')->all(),
            ]);

            return $released;
        });
    }

    /**
     * Normalizacja wejścia + SORTOWANIE — to nie kosmetyka, tylko ochrona przed deadlockiem.
     *
     * Gdy user A blokuje miejsca {5, 9}, a user B {9, 5} i każdy idzie w swojej kolejności,
     * A trzyma 5 i czeka na 9, B trzyma 9 i czeka na 5. PostgreSQL wykryje deadlock (40P01)
     * i zabije jedną transakcję — losowy użytkownik dostanie błąd 500. Przy stałej kolejności
     * rosnącej deadlock jest strukturalnie niemożliwy.
     *
     * @param  array<int|string>  $seatIds
     * @return list<int>
     */
    private function normalizeSeatIds(array $seatIds): array
    {
        $seatIds = array_map(static fn ($id): int => (int) $id, array_values($seatIds));

        if ($seatIds === []) {
            throw InvalidSeatSelectionException::emptySelection();
        }

        $duplicates = array_values(array_unique(array_diff_assoc($seatIds, array_unique($seatIds))));

        if ($duplicates !== []) {
            throw InvalidSeatSelectionException::duplicates($duplicates);
        }

        sort($seatIds);

        return $seatIds;
    }

    private function assertScreeningIsBookable(Screening $screening): void
    {
        if (! $screening->status->isBookable() || $screening->starts_at->isPast()) {
            throw ScreeningNotBookableException::for($screening);
        }
    }

    /**
     * Miejsce musi należeć do SALI TEGO SEANSU. Bez tej kontroli można by zablokować
     * fotel z innego kina — tabela seat_locks nie ma ograniczenia wiążącego salę z seansem,
     * bo klucz obcy prowadzi do seats, a nie do halls.
     *
     * @param  list<int>  $seatIds
     */
    private function assertSeatsBelongToScreening(Screening $screening, array $seatIds): void
    {
        $seats = Seat::query()->whereIn('id', $seatIds)->get(['id', 'hall_id', 'is_active']);

        $unknown = array_values(array_unique(array_merge(
            array_diff($seatIds, $seats->pluck('id')->all()),          // nieistniejące
            $seats->where('hall_id', '!=', $screening->hall_id)->pluck('id')->all(), // z innej sali
        )));

        if ($unknown !== []) {
            throw InvalidSeatSelectionException::seatsNotInHall($unknown);
        }

        $inactive = array_values($seats->where('is_active', false)->pluck('id')->all());

        if ($inactive !== []) {
            throw InvalidSeatSelectionException::inactiveSeats($inactive);
        }
    }

    /**
     * Limit liczymy dla CAŁEJ sesji na seansie, nie dla pojedynczego żądania —
     * inaczej wystarczyłoby wysłać dziesięć żądań po jednym miejscu.
     */
    private function assertWithinLimit(Screening $screening, string $sessionId, int $additional, Carbon $now): void
    {
        if ($additional === 0) {
            return;
        }

        $max = (int) config('cinema.seat_lock.max_seats_per_session', 10);

        $held = SeatLock::query()
            ->where('screening_id', $screening->id)
            ->where('session_id', $sessionId)
            ->whereNull('released_at')
            ->where('expires_at', '>', $now)
            ->count();

        if ($held + $additional > $max) {
            throw new SeatLockLimitExceededException($max, $held + $additional);
        }
    }

    /** @param list<int> $seatIds @return list<int> */
    private function ownedSeatIds(Screening $screening, array $seatIds, string $sessionId, Carbon $now): array
    {
        return SeatLock::query()
            ->where('screening_id', $screening->id)
            ->whereIn('seat_id', $seatIds)
            ->where('session_id', $sessionId)
            ->whereNull('released_at')
            ->where('expires_at', '>', $now)
            ->pluck('seat_id')
            ->all();
    }

    /** Miejsca zajęte przez KOGOŚ INNEGO: cudza żywa blokada albo sprzedany bilet.
     *  @param list<int> $seatIds @return list<int> */
    private function takenSeatIds(Screening $screening, array $seatIds, string $sessionId): array
    {
        $locked = SeatLock::query()
            ->where('screening_id', $screening->id)
            ->whereIn('seat_id', $seatIds)
            ->where('session_id', '!=', $sessionId)
            ->whereNull('released_at')
            ->where('expires_at', '>', Carbon::now())
            ->pluck('seat_id')
            ->all();

        return array_values(array_unique(array_merge($locked, $this->soldSeatIds($screening, $seatIds))));
    }

    /** @param list<int> $seatIds @return list<int> */
    private function soldSeatIds(Screening $screening, array $seatIds): array
    {
        return Ticket::query()
            ->where('screening_id', $screening->id)
            ->whereIn('seat_id', $seatIds)
            ->where('status', '!=', TicketStatus::Cancelled)
            ->pluck('seat_id')
            ->all();
    }

    /** Etykiety typu "B7" — użytkownik nie zna wewnętrznych identyfikatorów miejsc.
     *  @param list<int> $seatIds @return list<string> */
    private function labelsFor(array $seatIds): array
    {
        if ($seatIds === []) {
            return [];
        }

        return Seat::query()
            ->whereIn('id', $seatIds)
            ->orderBy('row_label')
            ->orderBy('seat_number')
            ->get()
            ->map(fn (Seat $seat): string => $seat->label)
            ->all();
    }

    /**
     * Zwalnia blokady WYGASŁE, ale niezwolnione — tylko na wskazanych miejscach.
     *
     * TO JEST SERCE OBEJŚCIA PUŁAPKI Z INDEKSU CZĘŚCIOWEGO.
     * Predykat seat_locks_active_unique brzmi "WHERE released_at IS NULL" i nie może
     * zawierać "AND expires_at > now()", bo PostgreSQL wymaga tam funkcji IMMUTABLE,
     * a now() jest STABLE. Skutek: blokada, której TTL minął, wciąż zajmuje wpis
     * w indeksie i blokuje nasz INSERT.
     *
     * Dlatego wołamy to WEWNĄTRZ transakcji lock(), tuż przed wstawieniem własnych
     * blokad — między zwolnieniem a INSERT-em nie ma okna, w które ktoś mógłby wejść.
     *
     * Współbieżność: gdy dwie transakcje zwalniają tę samą wygasłą blokadę, UPDATE
     * zakłada lock na wierszu. Druga czeka, a po zwolnieniu locka PostgreSQL ponownie
     * sprawdza warunek WHERE na nowej wersji wiersza (mechanizm EvalPlanQual), widzi
     * wypełnione released_at i aktualizuje zero wierszy. Bez podwójnego zwolnienia.
     *
     * @param  list<int>  $seatIds
     * @return int  liczba zwolnionych blokad
     */
    private function releaseExpiredLocks(Screening $screening, array $seatIds, Carbon $now): int
    {
        // Etap 7, blok J: najpierw FOR UPDATE we wspólnej kolejności, potem UPDATE po id.
        // Sam UPDATE też blokuje wiersze, ale w kolejności wybranej przez planer zapytań,
        // której nie kontrolujemy. FOR UPDATE ponownie sprawdza WHERE na najnowszej wersji
        // wiersza (ten sam EvalPlanQual), więc blokada zwolniona w międzyczasie odpada.
        $ids = SeatLock::query()
            ->where('screening_id', $screening->id)
            ->whereIn('seat_id', $seatIds)
            ->whereNull('released_at')
            ->where('expires_at', '<=', $now)
            ->inLockOrder()
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return SeatLock::query()
            ->whereIn('id', $ids)
            ->update(['released_at' => $now, 'updated_at' => $now]);
    }
}
