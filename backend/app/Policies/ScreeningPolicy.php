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

    /**
     * Oglądanie planu sali na żywo: kanał private-screenings.{id} (Etap 6).
     *
     * ?User — Gate wpuszcza gościa tylko wtedy, gdy parametr użytkownika
     * dopuszcza null (Gate::parameterAllowsGuests). Blokować miejsca można
     * bez konta (decyzja 8), więc plan sali na żywo też musi działać bez konta.
     *
     * Reguła jest ta sama co w SeatLockService::assertScreeningIsBookable():
     * kanał ma sens tylko dla seansu, na który wciąż się sprzedaje. Dane na
     * kanale są równoważne publicznemu GET seat-map, więc to bramka (seans
     * w sprzedaży + limit żądań), a nie ochrona tajemnicy.
     */
    public function watchSeatMap(?User $user, Screening $screening): bool
    {
        return $screening->status->isBookable() && $screening->starts_at->isFuture();
    }

    /**
     * Planowanie repertuaru w panelu (Etap 7, blok G2) — wyłącznie administrator.
     * Czy KONKRETNY seans da się zmienić (sprzedaż, stan), rozstrzyga serwis.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Screening $screening): bool
    {
        return $user->isAdmin();
    }
}
