<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

/**
 * Artykuły w panelu (Etap 7, blok M) — wyłącznie administrator. Obsługa kina
 * ma panel do sprzedaży i wejść na salę, nie do treści całej sieci.
 */
final class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Article $article): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->isAdmin();
    }
}
