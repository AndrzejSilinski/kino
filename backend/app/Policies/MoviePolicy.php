<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Movie;
use App\Models\User;

/**
 * Filmy w panelu (Etap 7, blok F). Laravel wykrywa klasę po nazwie
 * (App\Models\Movie -> App\Policies\MoviePolicy).
 *
 * Film jest wspólny dla całej sieci — zmiana tytułu czy plakatu widać
 * we wszystkich kinach — dlatego zarządza nim wyłącznie administrator.
 */
final class MoviePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Movie $movie): bool
    {
        return $user->isAdmin();
    }
}
