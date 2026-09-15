<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Booking;
use App\Notifications\BookingConfirmed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Zapisuje, że mail z biletami faktycznie wyszedł (decyzja 75).
 *
 * Znacznik ustawiamy po zdarzeniu NotificationSent, czyli PO wysłaniu,
 * a nie przy kolejkowaniu. Dzięki temu komenda ponawiająca (Blok G) widzi
 * jako "niewysłane" także maile, które utknęły w kolejce albo w failed_jobs.
 *
 * Warunek IS NULL w UPDATE: znacznik zostaje z pierwszej wysyłki, a dwa
 * równoległe procesy nie nadpiszą go sobie nawzajem.
 *
 * Kanał 'mail' sprawdzamy jawnie: w Etapie 8 dojdzie push (FCM) i jego
 * wysłanie nie może oznaczyć maila jako dostarczonego.
 */
final class MarkBookingConfirmationSent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof BookingConfirmed || $event->channel !== 'mail') {
            return;
        }

        Booking::query()
            ->whereKey($event->notification->bookingId)
            ->whereNull('confirmation_sent_at')
            ->update(['confirmation_sent_at' => now()]);
    }
}
