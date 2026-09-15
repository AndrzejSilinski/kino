<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Rejestracja nowego konta klienta.
 *
 * Nie ma tu pola 'role' i to jest celowe. Nawet gdyby klient je przysłał,
 * validated() go nie zwróci, bo zwraca wyłącznie pola objęte regułami.
 * Model User dodatkowo nie ma 'role' w #[Fillable]. Dwie niezależne
 * bariery przed privilege escalation — jedna by wystarczyła, ale ta
 * druga chroni przed przyszłym nieuważnym $request->all().
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Rejestracja jest publiczna — autoryzacji nie ma czego sprawdzać.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ];
    }

    /**
     * Tylko komunikaty specyficzne dla tego formularza.
     * Reszta przychodzi z lang/pl/validation.php.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Konto z tym adresem e-mail już istnieje.',
        ];
    }

    /** Normalizacja przed walidacją — e-mail bez spacji i wielkich liter. */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /** Nazwa urządzenia staje się nazwą tokenu Sanctuma. */
    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return $name !== '' ? $name : 'nieznane urządzenie';
    }
}
