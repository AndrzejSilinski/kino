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
     * Anulowanie rezerwacji przez klienta.
     *
     * Na razie nikt — anulowanie i zwroty to temat Etapu 4 (Stripe),
     * bo wiążą się ze zwrotem płatności. Metoda istnieje, żeby było
     * widać, że ten przypadek został przemyślany, a nie pominięty.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        return false;
    }
}
