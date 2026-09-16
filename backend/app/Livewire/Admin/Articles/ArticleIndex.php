<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Articles;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Services\Admin\ArticleAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista artykułów w panelu (Etap 7, blok M): filtry, wyszukiwanie, usuwanie.
 *
 * Kolumna "Widoczność" pokazuje to, co widzi publiczne API: szkic, zaplanowany
 * (opublikowany z przyszłą datą) albo widoczny — redakcja nie musi zgadywać.
 */
#[Title('Aktualności')]
final class ArticleIndex extends Component
{
    use WithPagination;

    #[Url(as: 'typ', except: '')]
    public string $type = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Article::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function deleteArticle(int $articleId, ArticleAdminService $service): void
    {
        $article = Article::query()->findOrFail($articleId);
        $this->authorize('delete', $article);

        $service->delete($article);
        $this->notice = "Usunięto artykuł „{$article->title}”.";
    }

    public function paginationView(): string
    {
        return 'livewire.admin.partials.pagination';
    }

    public function render(): View
    {
        $this->authorize('viewAny', Article::class);

        $term = addcslashes(trim($this->search), '%_\\');

        $articles = Article::query()
            ->with(['movie:id,title', 'author:id,name'])
            ->when(ArticleType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(ArticleStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($term !== '', fn (Builder $query) => $query->where('title', 'ilike', "%{$term}%"))
            ->orderByRaw('published_at DESC NULLS FIRST')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.articles.index', [
            'articles' => $articles,
            'types' => ArticleType::cases(),
            'statuses' => ArticleStatus::cases(),
            'timezone' => ArticleAdminService::TIMEZONE,
            'now' => now(),
        ]);
    }
}
