<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cinema;
use App\Models\User;

/**
 * Uprawnienia związane z kinem jako całością.
 *
 * Laravel wykrywa tę klasę po nazwie (App\Models\Cinema ->
 * App\Policies\CinemaPolicy), tak jak BookingPolicy i ScreeningPolicy.
 * W Etapie 7 dojdą tu uprawnienia panelu administracyjnego.
 */
final class CinemaPolicy
{
    /**
     * Feed sprzedaży całej sieci: kanał private-sales (Etap 6).
     *
     * Tylko administrator. Parametr User bez "?" sprawia, że Gate odmawia
     * gościowi, zanim w ogóle wywoła tę metodę.
     */
    public function viewAnySales(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Feed sprzedaży jednego kina: kanał private-cinemas.{id}.sales (Etap 6).
     *
     * Administrator — każde kino. Obsługa — wyłącznie kino, do którego jest
     * przypisana (users.cinema_id, pilnowane constraintem w bazie). Ta sama
     * zasada co ScreeningPolicy::validateTickets.
     */
    public function viewSales(User $user, Cinema $cinema): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff() && (int) $user->cinema_id === (int) $cinema->id;
    }
}
