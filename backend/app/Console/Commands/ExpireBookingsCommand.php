<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Wygaszanie nieopłaconych rezerwacji.
 *
 * Komenda jest cienka: cała logika siedzi w PaymentService, więc da się
 * ją przetestować bez uruchamiania konsoli. Konsola to tylko jedno
 * z wejść do systemu — drugim jest webhook, trzecim REST.
 *
 * Limit porcji chroni przed przebiegiem, który po awarii próbowałby
 * wygasić dziesiątki tysięcy rezerwacji naraz i wysłać tyle samo żądań
 * do Stripe'a. Zaległości nadrobią kolejne przebiegi.
 */
class ExpireBookingsCommand extends Command
{
    protected $signature = 'cinema:bookings:expire {--limit=100 : Maksymalna liczba rezerwacji na przebieg}';

    protected $description = 'Wygasza nieopłacone rezerwacje, zwalnia miejsca i anuluje płatności';

    public function handle(PaymentService $payments): int
    {
        $expired = $payments->expireAbandoned((int) $this->option('limit'));

        $this->info("Wygaszone rezerwacje: {$expired}");

        return self::SUCCESS;
    }
}
