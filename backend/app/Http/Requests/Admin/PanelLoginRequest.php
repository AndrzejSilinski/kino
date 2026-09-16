<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Formularz logowania do panelu (Etap 7, blok B3).
 *
 * Jak LoginRequest w API: walidujemy tylko obecność i typ pól, bez reguł
 * siły hasła — polityka haseł nie wycieka przed uwierzytelnieniem.
 * E-mail normalizujemy tutaj, żeby limiter w PanelAuthService liczył
 * "Admin@Cinema.test " i "admin@cinema.test" jako to samo konto.
 */
final class PanelLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Podaj adres e-mail.',
            'email.email' => 'Podaj poprawny adres e-mail.',
            'email.max' => 'Adres e-mail może mieć najwyżej 255 znaków.',
            'password.required' => 'Podaj hasło.',
            'password.max' => 'Hasło może mieć najwyżej 255 znaków.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
