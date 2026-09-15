<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeatLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Zwalnia wygasłe blokady miejsc.
 *
 * WAŻNE ROZRÓŻNIENIE: ta komenda to HIGIENA, nie mechanizm poprawności.
 * SeatLockService::lock() i tak zwalnia wygasłe blokady w swojej transakcji,
 * więc gdyby scheduler stanął, nikt nie kupiłby zajętego miejsca. Bez niej
 * miejsce, którego blokada wygasła, wyglądałoby jednak na zajęte w widoku
 * planu sali aż do chwili, gdy ktoś w nie kliknie — a klient ma zobaczyć,
 * że miejsce wróciło do puli, samo z siebie.
 *
 * Komenda jest bezpieczna do wywołania w dowolnym momencie i dowolną liczbę razy.
 */
class SweepExpiredSeatLocks extends Command
{
    protected $signature = 'cinema:seat-locks:sweep
                            {--limit= : Rozmiar porcji (domyślnie cinema.seat_lock.sweep_batch)}
                            {--max-passes=20 : Ile porcji maksymalnie w jednym uruchomieniu}';

    protected $description = 'Oznacza wygasłe blokady miejsc jako zwolnione.';

    public function handle(SeatLockService $seatLocks): int
    {
        $limit = (int) ($this->option('limit') ?? config('cinema.seat_lock.sweep_batch', 500));
        $maxPasses = (int) $this->option('max-passes');

        $total = 0;
        $passes = 0;

        // Pętla porcjami: jedno uruchomienie nadrabia zaległości po dłuższym
        // przestoju schedulera, ale ma twardy limit przebiegów, żeby nie kręcić
        // się w nieskończoność, gdyby coś stale dorzucało wygasłe blokady.
        do {
            $released = $seatLocks->sweepExpired($limit);
            $total += $released;
            $passes++;
        } while ($released === $limit && $passes < $maxPasses);

        if ($total > 0) {
            // Log tylko przy faktycznej pracy — komenda chodzi co minutę,
            // więc logowanie zer zapchałoby plik bez żadnej wartości.
            Log::info('Zwolniono wygasłe blokady miejsc.', ['released' => $total, 'passes' => $passes]);
        }

        $this->info("Zwolnione blokady: {$total} (przebiegi: {$passes})");

        return self::SUCCESS;
    }
}
