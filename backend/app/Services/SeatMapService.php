<?php

namespace App\Services;

use App\Enums\SeatType;
use App\Enums\TicketStatus;
use App\Models\Screening;
use App\Models\Seat;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Plan sali dla konkretnego seansu — stan każdego fotela.
 *
 * Łączy trzy niezależne źródła prawdy:
 *   seats       - statyczny układ sali (należy do SALI, nie do seansu)
 *   tickets     - miejsca sprzedane
 *   seat_locks  - miejsca trzymane przez czyjś koszyk
 *
 * BEZ DANYCH OSOBOWYCH. Cudza blokada to wyłącznie status "held" —
 * bez session_id, bez user_id, bez nazwiska. Ten sam payload pójdzie
 * w Etapie 6 przez Reverb, a wymóg 1.3 zadania mówi wprost, że
 * broadcast nie może zawierać danych osobowych. REST nie może być
 * bardziej rozmowny niż WebSocket.
 */
class SeatMapService
{
    public const STATUS_FREE = 'free';
    public const STATUS_HELD = 'held';
    public const STATUS_HELD_BY_YOU = 'held_by_you';
    public const STATUS_SOLD = 'sold';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @return array{
     *     seats: array<int, array<string, mixed>>,
     *     summary: array<string, int>
     * }
     */
    public function build(Screening $screening, ?string $sessionId): array
    {
        // 1. Układ sali. Sortowanie po współrzędnych, żeby frontend mógł
        //    rysować sekwencyjnie bez własnego sortowania.
        $seats = Seat::query()
            ->where('hall_id', $screening->hall_id)
            ->with('priceCategory')
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get();

        // 2. Cennik seansu: kategoria -> cena w groszach.
        //    Wywołanie prices() (metoda, nie właściwość) to osobne
        //    zapytanie, więc preventLazyLoading nie protestuje.
        $prices = $screening->prices()->pluck('price', 'price_category_id')->all();

        // 3. Sprzedane miejsca. flip() zamienia listę id w mapę
        //    id => indeks, dzięki czemu sprawdzenie to O(1), a nie
        //    in_array() po tablicy przy każdym z 200 foteli.
        $sold = $screening->tickets()
            ->where('status', '!=', TicketStatus::Cancelled->value)
            ->pluck('seat_id')
            ->flip()
            ->all();

        // 4. Aktywne blokady. Warunek "expires_at > teraz" jest KONIECZNY
        //    w PHP, bo częściowy indeks w bazie nie może go zawierać
        //    (now() jest STABLE, a indeks wymaga IMMUTABLE — pułapka A
        //    z Etapu 2). Wygasła, ale niezwolniona blokada dalej siedzi
        //    w tabeli i bez tego warunku pokazywalibyśmy ją jako zajętą.
        $held = $screening->seatLocks()
            ->whereNull('released_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->get(['seat_id', 'session_id', 'expires_at'])
            ->keyBy('seat_id');

        $now = CarbonImmutable::now();

        $summary = [
            self::STATUS_FREE => 0,
            self::STATUS_HELD => 0,
            self::STATUS_HELD_BY_YOU => 0,
            self::STATUS_SOLD => 0,
            self::STATUS_UNAVAILABLE => 0,
        ];

        $mapped = [];

        foreach ($seats as $seat) {
            $lock = $held->get($seat->id);
            $isMine = $lock !== null
                && $sessionId !== null
                && hash_equals($lock->session_id, $sessionId);

            $status = match (true) {
                ! $seat->is_active => self::STATUS_UNAVAILABLE,
                isset($sold[$seat->id]) => self::STATUS_SOLD,
                $isMine => self::STATUS_HELD_BY_YOU,
                $lock !== null => self::STATUS_HELD,
                default => self::STATUS_FREE,
            };

            $summary[$status]++;

            $priceMinor = $prices[$seat->price_category_id] ?? null;

            $mapped[] = [
                'id' => $seat->id,
                'row' => $seat->row_label,
                'number' => $seat->seat_number,
                'label' => $seat->label,
                'type' => $seat->type->value,
                'type_label' => $this->seatTypeLabel($seat->type),
                'position' => ['x' => $seat->position_x, 'y' => $seat->position_y],
                'category' => [
                    'id' => $seat->priceCategory?->id,
                    'name' => $seat->priceCategory?->name,
                    'color' => $seat->priceCategory?->color,
                ],
                'price' => $priceMinor !== null ? Money::minor($priceMinor)->toArray() : null,
                'status' => $status,
                // Czas wygaśnięcia TYLKO dla własnej blokady. Przy cudzej
                // byłby wyciekiem informacji o cudzym koszyku.
                'lock_expires_at' => $isMine ? $lock->expires_at->toIso8601String() : null,
                'lock_expires_in_seconds' => $isMine
                    ? max(0, $now->diffInSeconds($lock->expires_at, false))
                    : null,
            ];
        }

        $summary['total'] = count($mapped);

        return ['seats' => $mapped, 'summary' => $summary];
    }

    /**
     * Etykiety po polsku obok surowej wartości enuma.
     *
     * Dzięki temu ani Vue, ani Flutter nie muszą utrzymywać własnego
     * słownika tłumaczeń — a gdy dojdzie czwarty typ miejsca, zmiana
     * jest w jednym pliku, nie w trzech repozytoriach.
     */
    private function seatTypeLabel(SeatType $type): string
    {
        return match ($type) {
            SeatType::Standard => 'Miejsce standardowe',
            SeatType::Double => 'Miejsce podwójne (love seat)',
            SeatType::Accessible => 'Miejsce dla osoby z niepełnosprawnością',
        };
    }
}
