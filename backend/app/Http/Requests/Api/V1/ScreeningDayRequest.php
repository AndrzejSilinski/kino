<?php

namespace App\Http\Requests\Api\V1;

use App\Services\RepertoireService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parametry repertuaru dnia (?date=…&per_page=…).
 *
 * date_format:Y-m-d zamiast zwykłego 'date' jest celowe. Reguła 'date'
 * przepuściłaby "next friday" albo "11/09/2026", a wtedy dwóch klientów
 * pytających o ten sam dzień dostałoby różne odpowiedzi zależnie od
 * tego, jak Carbon zinterpretuje ich zapis. Sztywny format to kontrakt.
 */
class ScreeningDayRequest extends FormRequest
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
            'date' => ['sometimes', 'date_format:Y-m-d'],
            // Górny limit chroni przed ?per_page=100000, czyli przed
            // wyciągnięciem całej tabeli jednym żądaniem.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Data musi być w formacie RRRR-MM-DD, na przykład 2026-09-11.',
        ];
    }

    /** Brak parametru = dzisiaj W STREFIE KINA, nie w strefie serwera. */
    public function dateOrToday(string $timezone): string
    {
        return (string) ($this->validated('date')
            ?? CarbonImmutable::now($timezone)->toDateString());
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? RepertoireService::DEFAULT_PER_PAGE);
    }
}
