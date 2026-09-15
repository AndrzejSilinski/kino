<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\ScreeningStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screening extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'hall_id',
        'starts_at',
        'ends_at',
        'slot_ends_at',
        'projection_type',
        'language_version',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'slot_ends_at' => 'datetime',
            'projection_type' => ProjectionType::class,
            'language_version' => LanguageVersion::class,
            'status' => ScreeningStatus::class,
        ];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    /**
     * Cennik: jedna cena na kategorie miejsca.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ScreeningPrice::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
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
     * Seanse, na ktore mozna jeszcze sprzedawac bilety.
     */
    public function scopeBookable(Builder $query): void
    {
        $query->where('status', ScreeningStatus::Scheduled)
            ->where('starts_at', '>', now());
    }

    /**
     * Cena biletu dla danej kategorii miejsca, w groszach.
     * Zwraca null, gdy cennik seansu nie obejmuje tej kategorii.
     */
    public function priceFor(int $priceCategoryId): ?int
    {
        return $this->prices->firstWhere('price_category_id', $priceCategoryId)?->price;
    }
}
