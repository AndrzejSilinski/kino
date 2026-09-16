<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(4), '.');

        return [
            'type' => ArticleType::News,
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => fake()->sentence(12),
            'body' => "## Nagłówek\n\n".fake()->paragraph(),
            'status' => ArticleStatus::Draft,
            'published_at' => null,
            'movie_id' => null,
            'author_id' => null,
        ];
    }

    public function published(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published,
            'published_at' => $at ?? now()->subHour(),
        ]);
    }

    public function premiere(?Movie $movie = null): static
    {
        return $this->state(fn (): array => [
            'type' => ArticleType::Premiere,
            'movie_id' => $movie?->id ?? Movie::factory(),
        ]);
    }
}
