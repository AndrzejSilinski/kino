<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Screening;
use App\Models\User;

/**
 * Uprawnienia do działań na konkretnym seansie.
 *
 * Laravel wykrywa tę klasę po nazwie (App\Models\Screening ->
 * App\Policies\ScreeningPolicy), tak jak BookingPolicy.
 */
final class ScreeningPolicy
{
    /**
     * Skanowanie biletów przy wejściu na salę (decyzja 80).
     *
     * Administrator — na każdym seansie. Obsługa — tylko w kinie, do którego
     * jest przypisana (users.cinema_id, pilnowane constraintem w bazie).
     * Klient — nigdy. Pytanie dotyczy KONKRETNEGO seansu, a nie samej roli:
     * middleware z rolą wpuściłby obsługę z Krakowa na seans w Warszawie.
     */
    public function validateTickets(User $user, Screening $screening): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isStaff()) {
            return false;
        }

        return (int) $screening->loadMissing('hall')->hall->cinema_id === (int) $user->cinema_id;
    }
}
