<?php

declare(strict_types=1);

namespace App\Jobs\Diagnostics;

use App\Queue\UsesRetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sonda kolejki: sprawdza na żywym środowisku, że worker przetwarza
 * zadania i że polityka ponowień jest faktycznie stosowana.
 *
 * Istnieje jako prawdziwa klasa, bo zadania-closure nie da się
 * zakolejkować z `tinker --execute`: kod z eval() nie ma pliku źródłowego,
 * a serializacja closure odczytuje kod właśnie z pliku (pułapka X).
 *
 * Właściwości NIE są readonly. SerializesModels odtwarza zadanie w workerze,
 * ustawiając właściwości przez refleksję, i nie ma w nim obsługi readonly.
 */
final class QueueProbeJob implements ShouldQueue
{
    use Queueable;
    use UsesRetryPolicy;

    public function __construct(
        public string $probeId,
        public bool $shouldFail = false,
    ) {}

    public function handle(): void
    {
        Log::info('Sonda kolejki: próba wykonania.', [
            'probe' => $this->probeId,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        if ($this->shouldFail) {
            throw new RuntimeException('Sonda kolejki: celowa awaria w próbie '.$this->attempts().'.');
        }
    }
}
