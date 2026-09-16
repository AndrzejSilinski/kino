<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artykuł "Aktualności" / "Nadchodzące premiery" (Etap 7, blok M).
 *
 * status, published_at, slug i author_id celowo NIE są fillable: ustawia je
 * ArticleAdminService jawnie (pułapka BO — fill() po cichu pomija takie pola,
 * więc brak na liście jest zabezpieczeniem, a nie przeoczeniem).
 */
class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'excerpt',
        'body',
        'movie_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ArticleType::class,
            'status' => ArticleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Czy publiczne API pokazuje ten artykuł w chwili $now. */
    public function isVisibleAt(CarbonInterface $now): bool
    {
        return $this->status === ArticleStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo($now);
    }
}
