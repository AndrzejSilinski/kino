<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScreeningLifecycleService;
use Illuminate\Console\Command;

/**
 * Wejście dla schedulera: seanse po zakończeniu filmu dostają status finished.
 * Logika w ScreeningLifecycleService, tu tylko wywołanie i wynik.
 */
final class FinishScreeningsCommand extends Command
{
    protected $signature = 'cinema:screenings:finish';

    protected $description = 'Oznacza zakończone seanse statusem finished';

    public function handle(ScreeningLifecycleService $screenings): int
    {
        $count = $screenings->finishEnded();

        $this->line('Seanse oznaczone jako zakończone: '.$count);

        return self::SUCCESS;
    }
}
