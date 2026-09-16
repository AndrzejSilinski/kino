<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Kto może oglądać rezerwację.
 *
 * Laravel 11+ odnajduje tę klasę automatycznie: dla modelu
 * App\Models\Booking szuka App\Policies\BookingPolicy. Nie ma potrzeby
 * niczego rejestrować w service providerze — konwencja wystarczy.
 *
 * Policy działa NA POZIOMIE ZASOBU, nie endpointu. Middleware z rolą
 * potrafi odpowiedzieć tylko "czy wolno wejść na ten adres". Tutaj
 * pytanie brzmi "czy wolno zobaczyć TEN rekord", a odpowiedź zależy
 * od tego, czyja jest rezerwacja.
 */
class BookingPolicy
{
    /**
     * Lista własnych rezerwacji.
     *
     * Zwraca true dla każdego zalogowanego, bo zapytanie i tak jest
     * zawężone do własnych rekordów w kontrolerze. Ta metoda broni
     * jedynie przed wejściem bez tokenu.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Podgląd pojedynczej rezerwacji.
     *
     * Porównujemy identyfikatory, nie obiekty. Administrator widzi
     * wszystko — będzie tego potrzebował w panelu w Etapie 7 oraz
     * przy anulowaniu rezerwacji z podaniem powodu (sekcja 2.3).
     */
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id || $user->isAdmin();
    }

    /**
     * Lista rezerwacji w panelu (Etap 7, blok I): administrator i obsługa kina.
     * Zakres obsługi (tylko jej kino) zawęża zapytanie w komponencie.
     */
    public function viewAnyInPanel(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Szczegóły rezerwacji w panelu: administrator — każda; obsługa — tylko
     * rezerwacje seansów w swoim kinie. Inna reguła niż view() z API (właściciel).
     */
    public function viewInPanel(User $user, Booking $booking): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && (int) $booking->loadMissing('screening.hall')->screening->hall->cinema_id === (int) $user->cinema_id;
    }

    /**
     * Anulowanie rezerwacji z powodem i rozliczeniem płatności (Etap 7, blok K).
     *
     * WYŁĄCZNIE administrator — także nie obsługa kina (panel obsługi jest tylko
     * do odczytu) i nie klient (samodzielna rezygnacja ze zwrotem nie jest w zakresie
     * zadania). Czy rezerwację W OGÓLE da się anulować (status, start seansu,
     * wykorzystane bilety), rozstrzyga BookingService pod blokadą, a nie policy.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        return $user->isAdmin();
    }

    /**
     * Nasłuchiwanie kanału rezerwacji private-bookings.{reference} (Etap 6).
     *
     * WYŁĄCZNIE właściciel — inaczej niż view(), które wpuszcza też
     * administratora. Kanał niesie zmiany statusu konkretnego zakupu
     * (opłacona, bilety gotowe, wygasła) i służy ekranowi potwierdzenia
     * klienta. Administrator ma własny feed sprzedaży (private-sales),
     * więc nie ma powodu podsłuchiwać kanałów klientów.
     */
    public function listen(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }
}
