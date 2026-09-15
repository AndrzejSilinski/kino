<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Obserwowalność kolejki.
 *
 * Laravel sam zapisuje ostatecznie nieudane zadanie w failed_jobs, razem
 * z pełną treścią wyjątku. Tabela nie daje jednak znać sama z siebie —
 * ktoś musiałby do niej zajrzeć. Wpis w logu trafia na stderr, czyli do
 * `docker logs`, a na produkcji do systemu alertów.
 *
 * CO LOGUJEMY, A CZEGO NIE:
 * nazwę zadania, uuid, liczbę prób i KLASĘ wyjątku. Bez treści wyjątku
 * i bez danych zadania: komunikat odrzuconego maila potrafi zawierać adres
 * odbiorcy, a dane zadania — model z danymi klienta. Pełne szczegóły leżą
 * w failed_jobs, a z wpisem w logu łączy je uuid.
 *
 * JobFailed pojawia się RAZ, po wyczerpaniu wszystkich prób. Pojedyncze
 * nieudane próby zgłasza osobno handler wyjątków workera.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::failing(static function (JobFailed $event): void {
            Log::error('Zadanie z kolejki ostatecznie nieudane, zapisane w failed_jobs.', [
                'job' => $event->job->resolveName(),
                'uuid' => $event->job->uuid(),
                'attempts' => $event->job->attempts(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => $event->exception::class,
            ]);
        });
    }
}
