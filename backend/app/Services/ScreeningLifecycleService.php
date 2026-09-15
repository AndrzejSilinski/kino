<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ScreeningStatus;
use App\Models\Screening;
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
    /** @return int liczba seansów oznaczonych jako zakończone */
    public function finishEnded(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return Screening::query()
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '<', $now)
            ->update([
                'status' => ScreeningStatus::Finished,
                'updated_at' => $now,
            ]);
    }
}
