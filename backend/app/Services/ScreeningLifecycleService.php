<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ScreeningStatus;
use App\Models\Screening;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;

/**
 * Cykl życia seansu poza sprzedażą: oznaczanie zakończonych (Blok G).
 *
 * Status finished nie zmienia reguł sprzedaży ani wejścia na salę — te i tak
 * patrzą na czas (scopeBookable, okno walidacji biletów). Służy raportom
 * panelu admina (Etap 7) i temu, żeby zapytania o "aktywne" seanse nie
 * musiały każdorazowo porównywać dat.
 *
 * Granicą jest ends_at (koniec filmu), a nie slot_ends_at (koniec sprzątania):
 * dla widza i dla raportu sprzedaży seans kończy się wraz z napisami.
 *
 * Jeden UPDATE zamiast pętli po modelach: idempotentny (drugie uruchomienie
 * nic nie zmienia, bo warunek status = 'scheduled' już nie pasuje) i bez
 * ładowania tysięcy wierszy do pamięci po długiej przerwie schedulera.
 */
final class ScreeningLifecycleService
{
    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * @return int liczba seansów oznaczonych jako zakończone
     *
     * Etap 7: repertuar dnia w cache pokazuje wyłącznie seanse "scheduled".
     * Zakończony seans musi z niego zniknąć od razu, a nie po TTL — dlatego
     * podbijamy generacje kin, których seanse właśnie się zakończyły.
     * Kina ustalamy PRZED UPDATE: po nim warunek status = 'scheduled' już
     * nie pasuje. Oba zapytania używają tego samego $now, więc opisują ten
     * sam zbiór seansów; nadmiarowe podbicie byłoby zresztą nieszkodliwe.
     */
    public function finishEnded(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $cinemaIds = Screening::query()
            ->join('halls', 'halls.id', '=', 'screenings.hall_id')
            ->where('screenings.status', ScreeningStatus::Scheduled)
            ->where('screenings.ends_at', '<', $now)
            ->distinct()
            ->pluck('halls.cinema_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $finished = Screening::query()
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '<', $now)
            ->update([
                'status' => ScreeningStatus::Finished,
                'updated_at' => $now,
            ]);

        if ($finished > 0) {
            $this->catalog->bump(...array_map(CatalogCache::cinema(...), $cinemaIds));
        }

        return $finished;
    }
}
