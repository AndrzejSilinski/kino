<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Panel administracyjny (Etap 7).
 *
 * Osobny provider, jak RealtimeServiceProvider i TicketServiceProvider:
 * reguły jednego obszaru leżą razem, a AppServiceProvider nie puchnie.
 *
 * GATE panel.access to BRAMKA, a nie autoryzacja zasobu. Odpowiada tylko
 * na pytanie "czy ten użytkownik w ogóle wchodzi do panelu" (administrator
 * albo obsługa kina). Czy wolno zobaczyć TĘ rezerwację albo edytować TO
 * kino, rozstrzygają Policies na zasobie — tak samo jak w API (decyzja 29).
 *
 * Parametr User bez "?" sprawia, że gość dostaje odmowę, zanim Gate
 * w ogóle wywoła domknięcie.
 */
final class AdminPanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('panel.access', static fn (User $user): bool => $user->isAdmin() || $user->isStaff());
    }
}
