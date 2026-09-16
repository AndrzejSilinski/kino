<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\PanelLoginThrottledException;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Logowanie do panelu administracyjnego (Etap 7, blok B3).
 *
 * Sesja (guard 'web'), nie token: panel to strony renderowane przez serwer,
 * a ciasteczko HttpOnly jest poza zasięgiem JavaScriptu. API zostaje przy
 * tokenach Sanctum (decyzja 16) — dwa wejścia, jeden model User.
 *
 * Serwis nie dotyka Requestu ani sesji HTTP: regeneracja identyfikatora
 * sesji należy do kontrolera. Tu zapada tylko decyzja "kto" i "czy wolno",
 * więc da się ją przetestować bez żądania.
 *
 * TRZY ZASADY:
 * 1. Jeden komunikat dla złego hasła, nieistniejącego konta i konta klienta
 *    z poprawnym hasłem — panel nie zdradza, kto ma do niego dostęp
 *    (ta sama zasada co decyzja 18 w API).
 * 2. Dla nieistniejącego konta też liczymy hash — czas odpowiedzi nie
 *    zdradza, które adresy są w bazie (jak AuthService).
 * 3. Limit liczy WYŁĄCZNIE porażki, dwoma licznikami naraz: 5/min na
 *    konto+IP (zgadywanie hasła) i 20/min na IP (password spraying).
 *    Udane logowanie zeruje licznik konta.
 */
final class PanelAuthService
{
    private const MAX_FAILURES_PER_ACCOUNT = 5;

    private const MAX_FAILURES_PER_IP = 20;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Gate $gate,
        private readonly Hasher $hasher,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws PanelLoginThrottledException gdy wyczerpano limit nieudanych prób
     * @throws InvalidCredentialsException przy złych danych albo braku dostępu do panelu
     */
    public function login(string $email, string $password, string $ip): User
    {
        $accountKey = 'panel-login:'.$email.'|'.$ip;
        $ipKey = 'panel-login-ip:'.$ip;

        foreach ([$accountKey => self::MAX_FAILURES_PER_ACCOUNT, $ipKey => self::MAX_FAILURES_PER_IP] as $key => $max) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw new PanelLoginThrottledException($this->limiter->availableIn($key));
            }
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Koszt jednego bcrypta, jak Hash::check przy istniejącym koncie.
            $this->hasher->make($password);
        }

        $allowed = $user !== null
            && $this->hasher->check($password, $user->password)
            && $this->gate->forUser($user)->allows('panel.access');

        if (! $allowed) {
            $this->limiter->hit($accountKey, self::DECAY_SECONDS);
            $this->limiter->hit($ipKey, self::DECAY_SECONDS);

            throw new InvalidCredentialsException;
        }

        $this->limiter->clear($accountKey);
        $this->auth->guard('web')->login($user);

        return $user;
    }
}
