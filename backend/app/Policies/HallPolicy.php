<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Hall;
use App\Models\User;

/**
 * Sale w panelu administracyjnym (Etap 7, blok D).
 *
 * Strukturą kina zarządza wyłącznie administrator. Obsługa kina widzi
 * sprzedaż i plan sali swojego kina (bloki I i L), ale nie zmienia układu,
 * typów projekcji ani statusu sal.
 */
final class HallPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Hall $hall): bool
    {
        return $user->isAdmin();
    }
}
