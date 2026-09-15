<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'original_title',
        'description',
        'duration_minutes',
        'poster_path',
        'age_rating',
        'genres',
        'premiere_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'duration_minutes' => 'integer',
            'premiere_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
