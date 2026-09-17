<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Channels\PushChannel;
use App\Push\BookingPushContent;
use App\Push\PushMessage;
use App\Queue\UsesRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push "płatność przyjęta" (Etap 8, blok K, wymóg 3.5). Osobne powiadomienie, a nie drugi kanał
 * BookingConfirmed: mail czeka na PDF z biletami (łańcuch zadań, decyzja 54), a push ma przyjść
 * od razu po capture i nie może czekać na renderowanie PDF-a ani go powtarzać.
 */
final class PaymentConfirmedPush extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesRetryPolicy;

    public int $timeout = 30;

    public function __construct(public int $bookingId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [PushChannel::class];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Booking::query()->whereKey($this->bookingId)->where('status', BookingStatus::Paid)->exists();
    }

    public function toPush(object $notifiable): PushMessage
    {
        $booking = Booking::query()->with(['screening.movie', 'screening.hall.cinema'])->findOrFail($this->bookingId);

        return BookingPushContent::paymentConfirmed($booking);
    }
}
