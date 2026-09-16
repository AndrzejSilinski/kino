<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Articles;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Exceptions\InvalidArticleException;
use App\Models\Article;
use App\Models\Movie;
use App\Services\Admin\ArticleAdminService;
use App\Support\ArticleMarkdown;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tworzenie i edycja artykułu (Etap 7, blok M).
 *
 * PODGLĄD treści liczy ten sam ArticleMarkdown co publiczne API, więc redakcja
 * widzi dokładnie to, co dostaną klienci — łącznie z usuniętym surowym HTML-em.
 *
 * Datę i godzinę publikacji podaje się w strefie ArticleAdminService::TIMEZONE;
 * na UTC zamienia je serwis (z odrzuceniem godzin nieistniejących przy zmianie czasu).
 */
final class ArticleForm extends Component
{
    #[Locked]
    public ?int $articleId = null;

    public string $type = 'news';

    public string $title = '';

    public string $excerpt = '';

    public string $body = '';

    public string $movieId = '';

    public string $status = 'draft';

    public string $publishDate = '';

    public string $publishTime = '';

    public function mount(?Article $article = null): void
    {
        if ($article === null || ! $article->exists) {
            $this->authorize('create', Article::class);

            return;
        }

        $this->authorize('update', $article);

        $this->articleId = $article->id;
        $this->type = $article->type->value;
        $this->title = $article->title;
        $this->excerpt = $article->excerpt;
        $this->body = $article->body;
        $this->movieId = (string) ($article->movie_id ?? '');
        $this->status = $article->status->value;

        if ($article->published_at !== null) {
            $local = $article->published_at->setTimezone(ArticleAdminService::TIMEZONE);
            $this->publishDate = $local->format('Y-m-d');
            $this->publishTime = $local->format('H:i');
        }
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ArticleType::class)],
            'title' => ['required', 'string', 'max:200'],
            'excerpt' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:20000'],
            'movieId' => ['nullable', 'required_if:type,premiere', 'integer', Rule::exists('movies', 'id')],
            'status' => ['required', Rule::enum(ArticleStatus::class)],
            'publishDate' => ['nullable', 'date_format:Y-m-d', 'required_with:publishTime'],
            'publishTime' => ['nullable', 'date_format:H:i', 'required_with:publishDate'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'movieId.required_if' => 'Artykuł o premierze musi wskazywać film.',
            'publishDate.date_format' => 'Data publikacji musi mieć format RRRR-MM-DD.',
            'publishTime.date_format' => 'Godzina publikacji musi mieć format GG:MM.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'type' => 'rodzaj',
            'title' => 'tytuł',
            'excerpt' => 'zajawka',
            'body' => 'treść',
            'movieId' => 'film',
            'status' => 'status',
            'publishDate' => 'data publikacji',
            'publishTime' => 'godzina publikacji',
        ];
    }

    public function save(ArticleAdminService $service): void
    {
        $this->validate();

        try {
            $data = [
                'type' => ArticleType::from($this->type),
                'title' => $this->title,
                'excerpt' => $this->excerpt,
                'body' => $this->body,
                'movie_id' => $this->movieId === '' ? null : (int) $this->movieId,
                'status' => ArticleStatus::from($this->status),
                'published_at' => $service->localPublishTime($this->publishDate, $this->publishTime),
            ];

            if ($this->articleId === null) {
                $this->authorize('create', Article::class);
                $article = $service->create($data, auth()->user());
                session()->flash('status', "Dodano artykuł „{$article->title}” (adres: {$article->slug}).");
            } else {
                $article = Article::query()->findOrFail($this->articleId);
                $this->authorize('update', $article);
                $article = $service->update($article, $data);
                session()->flash('status', "Zapisano artykuł „{$article->title}”.");
            }
        } catch (InvalidArticleException $e) {
            $this->addError($e->field(), $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.articles.index');
    }

    public function render(): View
    {
        return view('livewire.admin.articles.form', [
            'editing' => $this->articleId !== null,
            'slug' => $this->articleId === null ? null : Article::query()->whereKey($this->articleId)->value('slug'),
            'types' => ArticleType::cases(),
            'statuses' => ArticleStatus::cases(),
            'movies' => Movie::query()->orderBy('title')->get(['id', 'title', 'premiere_date']),
            'previewHtml' => ArticleMarkdown::toHtml(mb_substr($this->body, 0, 20000)),
            'timezone' => ArticleAdminService::TIMEZONE,
        ])->title($this->articleId === null ? 'Nowy artykuł' : 'Edycja artykułu');
    }
}
