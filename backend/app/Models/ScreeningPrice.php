<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'screening_id',
        'price_category_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            // Cena w groszach - integer, nigdy float.
            'price' => 'integer',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }
}
