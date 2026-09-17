<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Zmiana hasła (Etap 8, blok I).
 *
 * Obecne hasło jest wymagane, choć klient ma już token: skradziony albo pozostawiony
 * w przeglądarce token nie może wystarczyć do przejęcia konta na stałe. Reguła
 * current_password:sanctum sprawdza je przez Hash::check dla użytkownika tokenu.
 * Polityka nowego hasła taka sama jak przy rejestracji.
 */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Obecne hasło jest nieprawidłowe.',
            'password.different' => 'Nowe hasło musi się różnić od obecnego.',
        ];
    }
}
