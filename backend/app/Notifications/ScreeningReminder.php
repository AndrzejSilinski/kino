<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Models\Booking;
use App\Queue\UsesRetryPolicy;
use App\Tickets\BookingTicketsPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Przypomnienie o seansie wysyłane przed startem (decyzje 89 i 90).
 *
 * Bez załącznika PDF: klient dostał bilety w mailu potwierdzającym i ma je
 * w historii zakupów. Ponowne generowanie PDF-a dla każdej rezerwacji
 * wieczornego seansu obciążałoby workera bez żadnej korzyści.
 *
 * shouldSend() sprawdza stan W CHWILI WYSYŁKI: rezerwacja mogła zostać
 * anulowana, seans odwołany albo już rozpoczęty, zanim zadanie doczekało
 * się workera.
 *
 * Kanał 'mail' teraz; push (FCM) dojdzie w Etapie 8 jako drugi kanał,
 * kolejkowany osobno.
 */
final class ScreeningReminder extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesRetryPolicy;

    /** Limit jednej próby wysłania. Mniejszy niż --timeout workera (60 s). */
    public int $timeout = 45;

    public function __construct(public int $bookingId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Booking::query()
            ->whereKey($this->bookingId)
            ->where('status', BookingStatus::Paid)
            ->whereHas('screening', fn (Builder $query) => $query
                ->where('status', ScreeningStatus::Scheduled)
                ->where('starts_at', '>', now()))
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = Booking::query()
            ->with(['screening.movie', 'screening.hall.cinema', 'tickets.seat'])
            ->findOrFail($this->bookingId);

        $view = app(BookingTicketsPresenter::class)->present($booking);
        $seats = implode('; ', array_column($view['tickets'], 'label'));

        return (new MailMessage())
            ->subject('Przypomnienie: '.$view['movie']['title'].', '.$view['screening']['date'].', '.$view['screening']['time'])
            ->greeting('Dzień dobry!')
            ->line('Przypominamy o seansie, na który masz bilety.')
            ->line('Film: '.$view['movie']['title'].' ('.$view['screening']['version'].')')
            ->line('Termin: '.$view['screening']['date'].', godz. '.$view['screening']['time'])
            ->line('Kino: '.$view['cinema']['name'].', '.$view['cinema']['address'].' · '.$view['cinema']['hall'])
            ->line('Miejsca: '.$seats)
            ->line('Seans poprzedza blok reklam (ok. '.$view['screening']['ads_minutes'].' min).')
            ->line('Przy wejściu pokaż kod QR z biletu: z pliku PDF w mailu potwierdzającym albo z historii zakupów w aplikacji.')
            ->line('Rezerwacja '.$view['reference'])
            ->salutation('Do zobaczenia w kinie!');
    }
}
