<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Queue\UsesRetryPolicy;
use App\Tickets\BookingTicketsPresenter;
use App\Tickets\TicketPdfStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Potwierdzenie zakupu z biletami w załączniku (decyzja 75).
 *
 * KOLEJKOWANA NOTYFIKACJA, a nie mail wysyłany wprost z zadania PDF:
 * Laravel kolejkuje każdy kanał osobno. W Etapie 8 dojdzie push (FCM)
 * i awaria pusha nie spowoduje ponownego wysłania maila.
 *
 * shouldSend() sprawdza stan W CHWILI WYSYŁKI, a nie kolejkowania:
 * rezerwacja mogła zostać wycofana albo mail mógł już wyjść z innego
 * zadania (ponowienie, komenda schedulera).
 *
 * Gwarancja to "co najmniej raz". Awaria dokładnie między wysłaniem maila
 * a zapisem znacznika (MarkBookingConfirmationSent) da drugi mail.
 * Świadomy wybór: duplikat jest lepszy niż brak biletów w skrzynce,
 * a "dokładnie raz" wymagałoby transakcji obejmującej serwer SMTP.
 *
 * Treść nie zawiera kodów biletów ani danych płatności. Kody są tylko
 * w obrazach QR w załączonym PDF-ie.
 */
final class BookingConfirmed extends Notification implements ShouldQueue
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
            ->whereNull('confirmation_sent_at')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = Booking::query()
            ->with(['screening.movie', 'screening.hall.cinema', 'tickets.seat'])
            ->findOrFail($this->bookingId);

        $store = app(TicketPdfStore::class);
        $view = app(BookingTicketsPresenter::class)->present($booking);
        $seats = implode('; ', array_column($view['tickets'], 'label'));

        return (new MailMessage())
            ->subject('Twoje bilety: '.$view['movie']['title'].', '.$view['screening']['date'].', '.$view['screening']['time'])
            ->greeting('Dzień dobry!')
            ->line('Dziękujemy za zakup. Płatność została przyjęta, a bilety znajdziesz w załączonym pliku PDF.')
            ->line('Film: '.$view['movie']['title'].' ('.$view['screening']['version'].')')
            ->line('Termin: '.$view['screening']['date'].', godz. '.$view['screening']['time'])
            ->line('Kino: '.$view['cinema']['name'].', '.$view['cinema']['address'].' · '.$view['cinema']['hall'])
            ->line('Miejsca: '.$seats)
            ->line('Rezerwacja '.$view['reference'].' · łącznie '.$view['total'])
            ->line('Przy wejściu na salę pokaż kod QR z pliku PDF — na telefonie albo wydrukowany.')
            ->salutation('Do zobaczenia w kinie!')
            ->attachData($store->contents($booking), $store->fileName($booking), ['mime' => 'application/pdf']);
    }
}
