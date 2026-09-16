<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\MovieCatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parametry listy filmów (?page=…&per_page=…).
 *
 * page walidujemy jawnie: bez tego ?page=abc albo ?page=-1 paginator po cichu
 * zamieniłby na stronę 1 i klient nie dowiedziałby się o błędzie.
 */
class MovieIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'page' => 'numer strony',
            'per_page' => 'liczba filmów na stronie',
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? MovieCatalogService::DEFAULT_PER_PAGE);
    }
}
