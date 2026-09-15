<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Skan biletu przez obsługę kina.
 *
 * Walidacja sprawdza wyłącznie kształt danych. Czy token jest prawdziwym
 * biletem, rozstrzyga TicketValidationService (podpis i baza), a czy ten
 * pracownik może skanować na tym seansie — ScreeningPolicy.
 *
 * Bez reguły exists:screenings,id: kontroler i tak pobiera seans
 * (findOrFail daje 404), więc exists byłoby drugim, zbędnym zapytaniem.
 */
final class ValidateTicketRequest extends FormRequest
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
            // 128 to górny limit długości tokenu w TicketTokenSigner.
            'token' => ['required', 'string', 'max:128'],
            'screening_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Brak odczytanego kodu QR.',
            'token.max' => 'Odczytany kod jest za długi, żeby był biletem.',
            'screening_id.required' => 'Nie wybrano seansu, na który obsługa wpuszcza widzów.',
            'screening_id.integer' => 'Identyfikator seansu musi być liczbą.',
        ];
    }
}
