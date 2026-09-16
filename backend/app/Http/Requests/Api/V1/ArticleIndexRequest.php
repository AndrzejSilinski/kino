<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ArticleType;
use App\Services\ArticleCatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Parametry listy artykułów (Etap 7, blok M). */
class ArticleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(ArticleType::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'type' => 'rodzaj artykułu',
            'page' => 'numer strony',
            'per_page' => 'liczba artykułów na stronie',
        ];
    }

    public function articleType(): ?ArticleType
    {
        return ArticleType::tryFrom((string) $this->validated('type'));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? ArticleCatalogService::DEFAULT_PER_PAGE);
    }
}
