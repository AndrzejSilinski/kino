<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Ponawianie rozliczeń płatności anulowanych rezerwacji (Etap 7, blok K).
 *
 * Cienka, jak ExpireBookingsCommand: logika siedzi w PaymentService::retryRefunds().
 * Wybiera rezerwacje z refund_requested_at bez refund_completed_at (indeks częściowy
 * bookings_refund_pending), starsze niż PaymentService::REFUND_RETRY_AFTER_SECONDS.
 *
 * Wyjście to same liczby — bez referencji, kwot i powodów anulowania.
 */
class RetryRefundsCommand extends Command
{
    protected $signature = 'cinema:bookings:retry-refunds {--limit=50 : Maksymalna liczba rezerwacji na przebieg}';

    protected $description = 'Ponawia zwolnienie autoryzacji albo zwrot płatności rezerwacji anulowanych przez administratora';

    public function handle(PaymentService $payments): int
    {
        $counts = $payments->retryRefunds(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Rozliczenia: zwroty %d, anulowane płatności %d, nadal zaległe %d, bez płatności %d',
            $counts['refunded'],
            $counts['voided'],
            $counts['pending'],
            $counts['not_required'],
        ));

        return self::SUCCESS;
    }
}
