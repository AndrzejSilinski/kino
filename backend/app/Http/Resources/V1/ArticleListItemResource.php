<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Artykuł na liście (Etap 7, blok M). Bez treści, bez autora i bez id:
 * kluczem w adresie jest slug, a kto pisał — sprawa redakcji.
 *
 * @mixin Article
 */
class ArticleListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'published_at' => $this->published_at?->toIso8601String(),
            'movie' => $this->movie_id === null ? null : [
                'slug' => $this->movie_slug,
                'title' => $this->movie_title,
            ],
        ];
    }
}
