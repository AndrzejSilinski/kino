<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingPaid;
use App\Models\Booking;
use App\Notifications\PaymentConfirmedPush;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * BookingPaid -> push "płatność przyjęta" (Etap 8, blok K).
 *
 * NAJWYŻEJ RAZ: bookings.payment_push_sent_at zajmowane warunkowym UPDATE (jak reminder_sent_at,
 * decyzja 89). BookingPaid może przyjść drugi raz (ponowienie webhooka po awarii capture), a drugie
 * "płatność przyjęta" na telefonie to gorszy błąd niż brak powiadomienia — bilety są w mailu i na koncie.
 *
 * Rezerwację zajmujemy także wtedy, gdy klient nie ma zgody albo urządzeń: zgoda wyrażona później
 * nie może wywołać spóźnionego powiadomienia o dawnej płatności.
 *
 * Błędy łapiemy i raportujemy: powiadomienie nie może zepsuć obsługi webhooka płatności.
 */
final class SendPaymentPush
{
    public function handle(BookingPaid $event): void
    {
        if (! config('push.enabled')) {
            return;
        }

        try {
            $claimed = Booking::query()
                ->whereKey($event->bookingId)
                ->where('status', BookingStatus::Paid)
                ->whereNull('payment_push_sent_at')
                ->update(['payment_push_sent_at' => CarbonImmutable::now()]);

            if ($claimed === 0) {
                return;
            }

            $user = Booking::query()->with('user')->findOrFail($event->bookingId)->user;

            if ($user->wantsPush() && $user->pushDevices()->exists()) {
                $user->notify(new PaymentConfirmedPush($event->bookingId));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
