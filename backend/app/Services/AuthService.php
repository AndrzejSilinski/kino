<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Rejestracja, logowanie i wylogowanie.
 *
 * Serwis nie wie nic o HTTP: nie dotyka Requestu ani Response, nie zna
 * kodów statusu. Dostaje proste typy, zwraca model albo rzuca wyjątek
 * domenowy. Dzięki temu ten sam kod obsłuży panel admina w Etapie 7,
 * a w testach da się go wywołać bez udawania żądania.
 */
class AuthService
{
    /**
     * Tworzy konto klienta i wydaje pierwszy token.
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data, string $deviceName): array
    {
        $user = DB::transaction(function () use ($data): User {
            $user = new User();

            // fill() respektuje #[Fillable], więc przepuści tylko te trzy pola.
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            // Rola ustawiana JAWNIE, poza fill(). Nie istnieje ścieżka,
            // którą klient mógłby na nią wpłynąć.
            $user->role = UserRole::Customer;
            $user->save();

            return $user;
        });

        return [
            'user' => $user,
            'token' => $this->issueToken($user, $deviceName),
        ];
    }

    /**
     * Weryfikuje dane i wydaje token dla urządzenia.
     *
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, string $deviceName): array
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            // Hashujemy "na pusto", żeby czas odpowiedzi był taki sam
            // jak przy istniejącym koncie. Bez tego różnica w czasie
            // zdradzałaby, które adresy e-mail są zarejestrowane
            // (user enumeration przez pomiar czasu).
            Hash::make($password);

            throw new InvalidCredentialsException();
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        return [
            'user' => $user,
            'token' => $this->issueToken($user, $deviceName),
        ];
    }

    /**
     * Kasuje TYLKO token bieżącego urządzenia.
     *
     * $user->tokens()->delete() wylogowałoby człowieka także z telefonu,
     * gdy klika "wyloguj" na laptopie. Jeden token = jedno urządzenie.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        // currentAccessToken() zwraca TransientToken przy uwierzytelnieniu
        // sesyjnym — wtedy nie ma czego kasować.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    private function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName)->plainTextToken;
    }
}
