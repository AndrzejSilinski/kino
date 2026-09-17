<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Profil, hasło i ustawienia powiadomień klienta (Etap 8, blok I).
 */
final class AccountService
{
    /**
     * Zmiana danych profilu. Tylko imię i nazwisko: e-mail jest loginem i adresem, na który
     * idą bilety, a jego zmiana bez potwierdzenia nowego adresu pozwoliłaby przejąć konto
     * (albo wysłać bilety literówką w obce ręce). Weryfikacja e-maila to ograniczenie w README.
     */
    public function updateProfile(User $user, string $name): User
    {
        $user->name = $name;
        $user->save();

        return $user;
    }

    /**
     * Nowe hasło i wylogowanie POZOSTAŁYCH urządzeń. Zmiana hasła to typowa reakcja na
     * podejrzenie, że ktoś zna stare — token wydany na nie dalej dawałby dostęp do konta.
     * Token bieżącego żądania zostaje: klient nie traci sesji w chwili, gdy ją zabezpiecza.
     *
     * @return int liczba unieważnionych tokenów
     */
    public function changePassword(User $user, string $newPassword, ?int $currentTokenId): int
    {
        return DB::transaction(function () use ($user, $newPassword, $currentTokenId): int {
            $user->password = $newPassword;
            $user->save();

            return PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))
                ->delete();
        });
    }

    /**
     * Ustawienia powiadomień. Zgoda na push zapisuje CHWILĘ jej udzielenia i nie nadpisuje jej
     * przy ponownym "włącz" — to ślad, kiedy klient się zgodził. Wyłączenie ją zeruje.
     *
     * @param  array{push_enabled?: bool, screening_reminders?: bool}  $settings
     */
    public function updateNotificationSettings(User $user, array $settings): User
    {
        if (array_key_exists('push_enabled', $settings)) {
            $user->push_consent_at = $settings['push_enabled']
                ? ($user->push_consent_at ?? CarbonImmutable::now())
                : null;
        }

        if (array_key_exists('screening_reminders', $settings)) {
            $user->screening_reminders = $settings['screening_reminders'];
        }

        $user->save();

        return $user;
    }
}
