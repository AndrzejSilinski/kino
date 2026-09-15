<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Diagnostics\QueueProbeJob;
use App\Queue\RetryPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Wstawia do kolejki zadanie próbne.
 *
 *   cinema:queue:probe          zadanie się uda: dowód, że worker żyje
 *   cinema:queue:probe --fail   zadanie zawodzi w każdej próbie: dowód,
 *                               że działa backoff i zapis do failed_jobs
 *
 * Przydaje się po każdym wdrożeniu (Etap 10): jedno polecenie zamiast
 * zgadywania, czy worker na serwerze w ogóle przetwarza kolejkę.
 */
final class QueueProbeCommand extends Command
{
    protected $signature = 'cinema:queue:probe
        {--fail : Zadanie rzuca wyjątek w każdej próbie}';

    protected $description = 'Wstawia do kolejki zadanie próbne (diagnostyka workera i ponowień)';

    public function handle(): int
    {
        $probeId = (string) Str::ulid();
        $shouldFail = (bool) $this->option('fail');

        QueueProbeJob::dispatch($probeId, $shouldFail);

        $this->components->info('Sonda '.$probeId.' jest w kolejce.');
        $this->components->twoColumnDetail('Scenariusz', $shouldFail ? 'awaria w każdej próbie' : 'sukces');
        $this->components->twoColumnDetail('Liczba prób', (string) RetryPolicy::MAX_ATTEMPTS);
        $this->components->twoColumnDetail(
            'Opóźnienia bazowe',
            implode(' s, ', RetryPolicy::baseDelays()).' s (+ do '.RetryPolicy::JITTER_PERCENT.'% losowo)',
        );

        return self::SUCCESS;
    }
}
