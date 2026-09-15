<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BookingNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Wejście dla schedulera: przypomnienia o seansach zaczynających się
 * w ciągu SCREENING_REMINDER_MINUTES minut (domyślnie 120).
 * Logika w BookingNotificationService, tu tylko wywołanie i wynik.
 */
final class SendScreeningRemindersCommand extends Command
{
    protected $signature = 'cinema:screenings:send-reminders';

    protected $description = 'Wysyła przypomnienia o nadchodzących seansach';

    public function handle(BookingNotificationService $notifications): int
    {
        $references = $notifications->sendDueReminders(
            minutesBefore: (int) config('tickets.reminders.minutes_before'),
        );

        if ($references !== []) {
            Log::info('Zakolejkowano przypomnienia o seansach.', ['bookings' => $references]);
        }

        $this->line('Wysłane przypomnienia: '.count($references));

        return self::SUCCESS;
    }
}
