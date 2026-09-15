<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Rejestr zmian stanu miejsc: wersja per seans (Etap 6, blok E).
 *
 * Każda transakcja, która zmienia stan miejsc (blokada, zwolnienie, sweep,
 * bilety, wygaśnięcie, zwrot), woła record() jako OSTATNIĄ instrukcję.
 *
 * DLACZEGO TO DAJE KOLEJNOŚĆ COMMITÓW:
 * INSERT ... ON CONFLICT DO UPDATE zakłada blokadę wiersza licznika i trzyma
 * ją do COMMIT. Kto pierwszy podbije licznik, ten pierwszy commituje; następna
 * transakcja czeka i dostaje numer o jeden większy. Transakcje, które odpadły
 * wcześniej (409 na indeksie UNIQUE), licznika nie dotykają — konflikt o miejsce
 * nadal rozstrzyga się równolegle, szeregowany jest tylko ten ostatni krok.
 *
 * DLACZEGO BEZ DEADLOCKÓW:
 * po podbiciu licznika transakcja nie czeka już na nic poza commitem. Gdy
 * jedna transakcja zmienia kilka seansów (sweep), podbija liczniki rosnąco
 * po screening_id — ta sama zasada co sortowanie seat_id w Etapie 2.
 *
 * W bloku F dojdzie tu rejestracja zdarzenia WebSocket po COMMIT.
 */
final class SeatStateRecorder
{
    public const FREE = SeatMapService::STATUS_FREE;

    public const HELD = SeatMapService::STATUS_HELD;

    public const SOLD = SeatMapService::STATUS_SOLD;

    private const STATUSES = [self::FREE, self::HELD, self::SOLD];

    /**
     * Rejestruje zmianę stanu miejsc seansu i zwraca jej numer wersji.
     *
     * Pusta zmiana (np. idempotentny retry blokady) nie podbija licznika
     * i zwraca null — klient nie dostanie zdarzenia, bo nic się nie zmieniło.
     *
     * @param  array<string, list<int>>  $seatsByStatus  np. ['held' => [311, 312]]
     *
     * @throws LogicException gdy wywołane poza transakcją
     * @throws InvalidArgumentException przy nieznanym statusie
     */
    public function record(int $screeningId, array $seatsByStatus): ?int
    {
        $changes = array_filter(
            array_map(static fn (array $ids): array => array_values(array_unique(array_map('intval', $ids))), $seatsByStatus),
            static fn (array $ids): bool => $ids !== [],
        );

        if ($changes === []) {
            return null;
        }

        foreach (array_keys($changes) as $status) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Nieznany status miejsca: {$status}");
            }
        }

        // Poza transakcją blokada licznika zwolniłaby się od razu po tym
        // zapytaniu, a numer wersji przestałby odpowiadać kolejności commitów.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SeatStateRecorder::record() musi być wywołane wewnątrz transakcji.');
        }

        // Parametr pozycyjny, nie nazwany: PDO pgsql nie przyjmuje dwa razy
        // tej samej nazwy (pułapka AH), a tu wystarczy jeden.
        $row = DB::selectOne(
            'INSERT INTO screening_seat_versions (screening_id, version, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (screening_id)
             DO UPDATE SET version = screening_seat_versions.version + 1, updated_at = now()
             RETURNING version',
            [$screeningId],
        );

        return (int) $row->version;
    }

    /** Aktualna wersja stanu miejsc seansu; 0, gdy nic się jeszcze nie zmieniło. */
    public function currentVersion(int $screeningId): int
    {
        return (int) (DB::table('screening_seat_versions')
            ->where('screening_id', $screeningId)
            ->value('version') ?? 0);
    }
}
