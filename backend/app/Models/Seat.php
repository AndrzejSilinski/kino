<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeatType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seat extends Model
{
    use HasFactory;

    protected $fillable = [
        'hall_id',
        'price_category_id',
        'row_label',
        'seat_number',
        'type',
        'position_x',
        'position_y',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => SeatType::class,
            'seat_number' => 'integer',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function seatLocks(): HasMany
    {
        return $this->hasMany(SeatLock::class);
    }

    /**
     * Czytelne oznaczenie miejsca, np. "B7". Uzywane na bilecie i w mailu.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->row_label.$this->seat_number);
    }

    /**
     * Miejsca wylaczone z uzytku (uszkodzone) nie trafiaja na plan sali.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
