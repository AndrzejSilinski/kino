<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\Booking;

/**
 * Treści powiadomień push o rezerwacji (Etap 8, blok K) w jednym miejscu.
 *
 * Widoczne na zablokowanym ekranie, więc tylko TYTUŁ FILMU I TERMIN seansu (decyzja z planu
 * Etapu 8) — bez imienia, e-maila, kina, miejsc i numeru rezerwacji. Godzina w strefie kina,
 * tak jak w repertuarze i w mailach (decyzja 24).
 */
final class BookingPushContent
{
    public static function paymentConfirmed(Booking $booking): PushMessage
    {
        return new PushMessage('Płatność przyjęta', self::when($booking), 'booking.paid', self::url($booking));
    }

    public static function screeningReminder(Booking $booking): PushMessage
    {
        return new PushMessage('Przypomnienie o seansie', self::when($booking), 'screening.reminder', self::url($booking));
    }

    /**
     * Kino odwołało seans (Etap 9, blok K).
     *
     * Tytuł mówi o SEANSIE, nie o rezerwacji: na zablokowanym ekranie „Rezerwacja anulowana"
     * brzmi jak coś, co klient zrobił sam, a tu decyzja należała do kina. Szczegóły —
     * pieniądze, numer rezerwacji — zostają w mailu; tu jest tylko tyle, żeby nie przyjść
     * pod kino.
     */
    public static function cancelledByCinema(Booking $booking): PushMessage
    {
        return new PushMessage('Kino odwołało seans', self::when($booking), 'booking.cancelled', self::url($booking));
    }

    private static function when(Booking $booking): string
    {
        $screening = $booking->screening;
        $startsAt = $screening->starts_at->copy()->setTimezone($screening->hall->cinema->timezone);

        return $screening->movie->title.' · '.$startsAt->format('d.m').', godz. '.$startsAt->format('H:i');
    }

    private static function url(Booking $booking): string
    {
        return '/bookings/'.$booking->reference;
    }
}
