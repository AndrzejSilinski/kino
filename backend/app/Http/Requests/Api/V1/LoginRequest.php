<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Logowanie.
 *
 * Walidacja jest tu CELOWO uboższa niż przy rejestracji: sprawdzamy
 * tylko, czy pola w ogóle przyszły i czy mają sensowny typ.
 *
 * Nie ma reguły Password::min(8), choć przy rejestracji jest. Powód:
 * gdyby była, komunikat 422 zdradzałby politykę haseł jeszcze przed
 * uwierzytelnieniem, a po zaostrzeniu polityki starzy użytkownicy
 * nie mogliby się zalogować własnym, krótszym hasłem. Weryfikacją
 * hasła zajmuje się Hash::check w AuthService, nie walidator.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return $name !== '' ? $name : 'nieznane urządzenie';
    }
}
