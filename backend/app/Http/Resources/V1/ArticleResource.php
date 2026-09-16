<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Article;
use Illuminate\Http\Request;

/**
 * Pełny artykuł (Etap 7, blok M). body_html jest już oczyszczony
 * (ArticleMarkdown: bez surowego HTML-a i niebezpiecznych linków) — klienci
 * mogą go wstawić do widoku jako HTML.
 *
 * @mixin Article
 */
class ArticleResource extends ArticleListItemResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'body_html' => (string) $this->body_html,
        ];
    }
}
