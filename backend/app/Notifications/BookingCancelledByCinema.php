<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Queue\UsesRetryPolicy;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Informacja dla klienta, że kino anulowało jego rezerwację (Etap 7, blok K).
 *
 * BEZ POWODU ANULOWANIA: to notatka wewnętrzna kina (kto, dlaczego, szczegóły
 * rozmowy z klientem). Klient dostaje fakt anulowania i informację o pieniądzach.
 *
 * shouldSend() sprawdza stan W CHWILI WYSYŁKI, jak BookingConfirmed: mail idzie
 * tylko dla rezerwacji anulowanej przez administratora (cancelled_by_user_id).
 *
 * Tekst o pieniądzach zależy od tego, czy rezerwacja była opłacona (paid_at),
 * a nie od wyniku rozliczenia: zwrot może się jeszcze ponawiać, a klient ma
 * wiedzieć, czego się spodziewać.
 */
final class BookingCancelledByCinema extends Notification implements ShouldQueue
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
            ->whereIn('status', [BookingStatus::Cancelled, BookingStatus::Refunded])
            ->whereNotNull('cancelled_by_user_id')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = Booking::query()
            ->with(['screening.movie', 'screening.hall.cinema'])
            ->findOrFail($this->bookingId);

        $screening = $booking->screening;
        $cinema = $screening->hall->cinema;
        // Decyzja 24: godzina seansu w strefie kina.
        $startsAt = $screening->starts_at->copy()->setTimezone($cinema->timezone)->locale('pl');
        $date = $startsAt->isoFormat('dddd, D MMMM YYYY');
        $total = Money::minor($booking->total_amount, $booking->currency)->toArray()['formatted'];

        $money = match (true) {
            $booking->paid_at !== null => 'Zwrócimy '.$total.' na metodę płatności użytą przy zakupie. Zwrot zwykle pojawia się na rachunku w ciągu kilku dni roboczych.',
            $booking->stripe_payment_intent_id !== null => 'Rezerwacja nie była opłacona. Rozpoczęta płatność zostanie anulowana — jeśli pieniądze zdążyły zostać pobrane, zwrócimy je na tę samą metodę płatności.',
            default => 'Rezerwacja nie była opłacona, więc nie pobraliśmy żadnych pieniędzy.',
        };

        return (new MailMessage)
            ->subject('Rezerwacja anulowana: '.$screening->movie->title.', '.$date.', '.$startsAt->format('H:i'))
            ->greeting('Dzień dobry!')
            ->line('Z przykrością informujemy, że kino anulowało Twoją rezerwację. Bilety z tej rezerwacji są nieważne.')
            ->line('Film: '.$screening->movie->title)
            ->line('Termin: '.$date.', godz. '.$startsAt->format('H:i'))
            ->line('Kino: '.$cinema->name.' · '.$screening->hall->name)
            ->line('Rezerwacja '.$booking->reference)
            ->line($money)
            ->line('Przepraszamy za kłopot. W razie pytań skontaktuj się z kinem i podaj numer rezerwacji.')
            ->salutation('Zespół kina');
    }
}
