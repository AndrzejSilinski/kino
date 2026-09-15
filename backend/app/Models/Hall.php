<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hall extends Model
{
    use HasFactory;

    protected $fillable = [
        'cinema_id',
        'name',
        'projection_types',
        'grid_rows',
        'grid_cols',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'projection_types' => 'array',
            'grid_rows' => 'integer',
            'grid_cols' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }

    /**
     * Czy sala obsluguje dana technologie projekcji.
     * Uzywane przy walidacji dodawania seansu do repertuaru.
     */
    public function supports(ProjectionType $type): bool
    {
        return in_array($type->value, $this->projection_types ?? [], strict: true);
    }
}
