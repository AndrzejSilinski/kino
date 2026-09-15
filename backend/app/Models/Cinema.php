<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Cinema extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'city',
        'address',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function halls(): HasMany
    {
        return $this->hasMany(Hall::class);
    }

    /**
     * Wszystkie seanse tego kina - przez sale.
     */
    public function screenings(): HasManyThrough
    {
        return $this->hasManyThrough(Screening::class, Hall::class);
    }

    /**
     * Tylko kina widoczne dla klienta.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * URL-e uzywaja sluga zamiast id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
