<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Czas zajęcia sali przez jeden seans (Etap 7, blok G).
 *
 *   startsAt ── reklamy ── film ── endsAt ── sprzątanie ── slotEndsAt
 *
 * endsAt widzi klient (koniec filmu), slotEndsAt pilnuje kolizji w sali.
 * Wszystkie momenty w UTC — strefa kina jest potrzebna tylko do zamiany
 * godziny lokalnej na moment (ScreeningTimeline::localStart).
 */
final class ScreeningSlot
{
    public function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly CarbonImmutable $slotEndsAt,
    ) {}

    /**
     * Przedziały PÓŁOTWARTE [startsAt, slotEndsAt): seans może zacząć się
     * dokładnie w chwili, w której poprzedni zwalnia salę. To ta sama semantyka
     * co tstzrange(starts_at, slot_ends_at) z operatorem && w constraincie
     * screenings_no_overlap i warunek w zapytaniu serwisu — trzy miejsca,
     * jedna definicja kolizji.
     */
    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->slotEndsAt
            && $other->startsAt < $this->slotEndsAt;
    }
}
