<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ArticleIndexRequest;
use App\Http\Resources\V1\ArticleListItemResource;
use App\Http\Resources\V1\ArticleResource;
use App\Models\Article;
use App\Services\ArticleCatalogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publiczne artykuły (Etap 7, blok M; wymóg 2.4). Tylko opublikowane, których
 * data publikacji już minęła; szkic i artykuł zaplanowany dają 404, jak nieistniejący.
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleCatalogService $articles,
    ) {}

    /** Lista opublikowanych artykułów, najnowsze pierwsze; filtr ?type=news|premiere. */
    public function index(ArticleIndexRequest $request): AnonymousResourceCollection
    {
        return ArticleListItemResource::collection($this->articles->published($request->articleType(), $request->perPage()));
    }

    /** Artykuł z treścią w HTML. */
    public function show(string $slug): ArticleResource
    {
        $article = $this->articles->findPublished($slug)
            ?? throw (new ModelNotFoundException)->setModel(Article::class);

        return new ArticleResource($article);
    }
}
