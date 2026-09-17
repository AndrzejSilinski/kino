<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\PushDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rejestracja albo odświeżenie urządzenia push (Etap 8, blok K) — ten sam kontrakt dla weba i Fluttera.
 *
 * replaces: poprzedni token tej instalacji, gdy FCM wydał nowy (odświeżenie tokenu). Stary wiersz
 * usuwamy w tej samej transakcji, żeby nie wysyłać dwóch powiadomień na jedno urządzenie.
 * Format tokenu tylko zgrubnie (znaki base64url i dwukropek): o ważności rozstrzyga FCM przy wysyłce.
 */
final class RegisterPushDeviceRequest extends FormRequest
{
    private const TOKEN_RULES = ['string', 'min:32', 'max:1024', 'regex:/\A[A-Za-z0-9_:\-]+\z/'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', ...self::TOKEN_RULES],
            'platform' => ['required', 'string', Rule::in(PushDevice::PLATFORMS)],
            'replaces' => ['sometimes', 'nullable', ...self::TOKEN_RULES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.min' => 'Nieprawidłowy token urządzenia.',
            'token.regex' => 'Nieprawidłowy token urządzenia.',
            'replaces.min' => 'Nieprawidłowy poprzedni token urządzenia.',
            'replaces.regex' => 'Nieprawidłowy poprzedni token urządzenia.',
            'platform.in' => 'Platforma musi być jedną z: web, android, ios.',
        ];
    }
}
