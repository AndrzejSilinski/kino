<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BookingNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Wejście dla schedulera: ponawia zgubione potwierdzenia zakupu.
 *
 *   cinema:bookings:resend-confirmations        tylko w oknie automatycznym
 *   cinema:bookings:resend-confirmations --all  także starsze (ręcznie)
 *
 * Każde ponowienie to sygnał, że główna ścieżka zawiodła (kolejka, worker,
 * SMTP), więc logujemy je jako ostrzeżenie — z referencjami rezerwacji,
 * bez danych klientów.
 */
final class ResendBookingConfirmationsCommand extends Command
{
    protected $signature = 'cinema:bookings:resend-confirmations
        {--all : Pomija górną granicę wieku płatności}';

    protected $description = 'Ponawia potwierdzenia zakupu, które nie zostały wysłane';

    public function handle(BookingNotificationService $notifications): int
    {
        $references = $notifications->resendMissingConfirmations(
            retryAfterMinutes: (int) config('tickets.confirmations.retry_after_minutes'),
            retryWindowMinutes: (int) config('tickets.confirmations.retry_window_minutes'),
            ignoreWindow: (bool) $this->option('all'),
        );

        if ($references !== []) {
            Log::warning('Ponowiono zgubione potwierdzenia zakupu.', ['bookings' => $references]);
        }

        $this->line('Ponowione potwierdzenia: '.count($references));

        return self::SUCCESS;
    }
}
