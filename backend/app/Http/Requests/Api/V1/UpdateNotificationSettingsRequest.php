<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ustawienia powiadomień (Etap 8, blok I): PATCH — przychodzi dowolny podzbiór pól,
 * ale co najmniej jedno. "accepted"/"declined" nie, bo to przełączniki w obie strony.
 */
final class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'push_enabled' => ['required_without:screening_reminders', 'boolean'],
            'screening_reminders' => ['required_without:push_enabled', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'push_enabled.required_without' => 'Podaj co najmniej jedno ustawienie powiadomień.',
            'screening_reminders.required_without' => 'Podaj co najmniej jedno ustawienie powiadomień.',
        ];
    }

    /** @return array{push_enabled?: bool, screening_reminders?: bool} */
    public function settings(): array
    {
        return array_map(fn ($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN), $this->safe()->only(['push_enabled', 'screening_reminders']));
    }
}
